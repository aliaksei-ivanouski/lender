# Wave 0 Data Foundation Architecture

**Task:** TASK-3  
**Date:** 2026-06-18  
**Status:** FINAL — ready for implementation

---

## 1. Overview

Wave 0 establishes the data infrastructure that every subsequent wave depends on:

- `event_images` table + placeholder JPEG files + storage link
- `events.location_city` column + offline geocoding service
- CITY_ANCHOR → IANA timezone static map (single source of truth)
- DB indexes for date + location filtering
- Bug fix for the broken filter button

No Wave 1 or Wave 2 work can proceed until all Wave 0 migrations are run and the city-anchor dataset is populated.

---

## 2. Decisions

### DEC-001: EventImage PK — bigint auto-increment (not UUID)

`event_images` uses an auto-increment `bigint` PK. Rationale: events themselves use UUID (identity stability required for URL routing); image rows are append-only, never directly addressable by URL, and at 2.5M rows a 16-byte UUID PK wastes ~40 MB of index space. BigInt is faster for the `ORDER BY sort_order` scan and halves FK storage. No UUID required here.

### DEC-002: Image seeding strategy — physical row per event-image pair, path is a short recycled string

**Decision:** Insert a real row in `event_images` for every event-image assignment (2 rows per event = 2.5M rows). The `path` column stores a recycled short string (e.g. `event-images/placeholder-03.jpg`) — no image bytes, no blob. Total extra DB size ≈ 2.5M × ~100 bytes per row ≈ 250 MB. This is acceptable; the schema allows future unique-image assignment without redesign.

**Alternative considered — deterministic derivation (no physical rows):** Store nothing; derive `image_path = 'event-images/placeholder-' . ((crc32($event_id) % 8) + 1) . '.jpg'` at query time. Pros: zero DB growth. Cons: breaks the relational model; `sort_order` is meaningless; adding a third image requires a schema change; AC-101-5 (relation `$event->images`) is violated — the BRS explicitly requires an Eloquent relation returning `EventImage` models ordered by `sort_order`. Rejected.

**Alternative considered — single mapping table (event→image slot via join):** Store one row per placeholder file name, then a pivot. Adds complexity for no gain at this scale.

Chosen: physical rows with recycled path strings. Image seeding is done in a dedicated `EventImageSeeder` called after `EventSeeder`, using the same `CHUNK=4000` bulk-insert pattern and the seeding PRAGMAs from `EventSeeder::withSeedingPragmas`.

### DEC-003: City/timezone static data lives in `app/Support/CityAnchor.php`

A single PHP class (not a config file) holds the authoritative array of `CityAnchor` structs (lat, lng, city, region, country, iana_tz). Rationale: config files are for environment-specific values; this data is purely domain knowledge that changes only when anchors are added. A PHP class gives type safety, namespace, and is directly importable by services, seeders, and artisan commands without calling `config()`. Config YAML would require a service wrapper anyway.

### DEC-004: `location_city` stores "CityName" (bare city name, NOT "City, Country")

The BRS acceptance criterion AC-102-2 says `location_city` is set to "the name of the nearest CITY_ANCHOR (e.g. 'New York')". The tz map in AC-106-1 is also keyed by city name (e.g. `"New York" => "America/New_York"`). Storing bare city name keeps the lookup O(1) with no string parsing. The frontend can compose "New York, USA" from city + country if needed (country is returned via a separate accessor or the CityAnchor dataset).

### DEC-005: Timezone NOT stored as a column — derived at read time from `location_city`

The BRS §5.1 states this explicitly: "Timezone is derived at read time from the static CITY_ANCHOR → IANA timezone map using `location_city` as the lookup key; it is NOT stored as a separate column." This keeps data consistent (no drift between `location_city` and `timezone`) and saves a migration.

### DEC-006: `from`/`to` date filter operates on event-local date (not UTC)

Per AC-104-1/2 and D-004. SQLite does not have a timezone-aware `DATE()` function, so the backend converts the user's date filter to a UTC unix timestamp window for that city's timezone, then applies `BETWEEN` on `created_time`. This avoids per-row function calls and keeps the `created_time` index usable.

### DEC-007: Composite index `(status, created_time)` is the primary listing index

The default listing query always has `ORDER BY created_time DESC`; `status` is the most common filter. SQLite can use a composite index `(status, created_time)` for both the WHERE and ORDER BY in a single scan when status is provided. For unfiltered queries (no status), the single-column `created_time` index covers the sort. Both indexes are needed (see §6).

### DEC-008: Backfill via chunked artisan command, NOT a raw SQL UPDATE

A bulk `UPDATE events SET location_city = CASE ...` with 78 distance calculations per row across 1.25M rows would lock the database. Instead: `php artisan events:geocode-cities` reads events in chunks of 4000, computes location_city in PHP (the nearest-anchor lookup is O(78) per event, trivially fast), and issues a bulk UPDATE per chunk with a `WHERE id IN (...)` clause. This matches the seeder pattern, respects SQLite's locking model, and can be re-run safely (idempotent: `WHERE location_city IS NULL`).

---

## 3. New Database Schema

### 3.1 Migration: `add_location_city_to_events_table`

**File:** `database/migrations/2026_06_18_000001_add_location_city_and_indexes_to_events_table.php`

```sql
ALTER TABLE events ADD COLUMN location_city VARCHAR(100) NULL;
CREATE INDEX events_location_city_index ON events (location_city);
CREATE INDEX events_created_time_index ON events (created_time);
CREATE INDEX events_status_created_time_index ON events (status, created_time);
```

Laravel Blueprint DDL:
```php
$table->string('location_city', 100)->nullable()->after('longitude');
$table->index('location_city');
$table->index('created_time');
$table->index(['status', 'created_time']);
```

**Notes:**
- `status` already has a single-column index (from the original migration). The composite `(status, created_time)` is additive — SQLite will prefer it for filtered+sorted queries. The original single-column `status` index can be dropped if desired (saves space) but is not strictly necessary to remove.
- SQLite does not support partial indexes in older versions; these are standard B-tree indexes on the full table.

### 3.2 Migration: `create_event_images_table`

**File:** `database/migrations/2026_06_18_000002_create_event_images_table.php`

```sql
CREATE TABLE event_images (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id    VARCHAR(36) NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    path        VARCHAR(255) NOT NULL,
    sort_order  INTEGER UNSIGNED NOT NULL DEFAULT 0,
    alt         VARCHAR(255) NULL,
    created_at  DATETIME NULL,
    updated_at  DATETIME NULL
);
CREATE INDEX event_images_event_id_sort_order_index ON event_images (event_id, sort_order);
```

Laravel Blueprint DDL:
```php
$table->id();
$table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
$table->string('path', 255);
$table->unsignedInteger('sort_order')->default(0);
$table->string('alt', 255)->nullable();
$table->timestamps();
$table->index(['event_id', 'sort_order']);
```

**Key constraint:** `foreignUuid('event_id')` — since `events.id` is a `uuid` (string PK via `HasUuids`), the FK column must be the same type. In Laravel Blueprint, `foreignUuid` generates `VARCHAR(36)` and links to `events.id`.

**Index justification:** The query `$event->images()->orderBy('sort_order')` becomes a covered index scan on `(event_id, sort_order)` — no full table scan.

---

## 4. Static City Anchor Dataset

### 4.1 `app/Support/CityAnchor.php` — Single Source of Truth

This class is the canonical home for all 78 city anchor definitions. It is imported by:
- `EventSeeder` (coordinates — replaces the inline `CITY_ANCHORS` constant)
- `GeocodingService` (nearest-anchor lookup)
- `TimezoneService` (city → IANA tz)
- `EventImageSeeder` (indirectly, via seeder context)
- `app/Console/Commands/GeocodeEventCities.php` (backfill command)
- `EventController` (city list for filter dropdown)

**Class contract:**

```php
namespace App\Support;

final class CityAnchor
{
    public function __construct(
        public readonly string $city,      // bare city name, e.g. "New York"
        public readonly string $region,    // state/province/country subdivision, e.g. "NY" or "Île-de-France"
        public readonly string $country,   // ISO 3166-1 alpha-2, e.g. "US"
        public readonly float  $lat,
        public readonly float  $lng,
        public readonly string $ianaTimezone, // e.g. "America/New_York"
    ) {}

    /** @return list<self> */
    public static function all(): array { ... }

    /** @return array<string, string>  city => ianaTimezone */
    public static function timezoneMap(): array { ... }

    /** @return list<string> sorted list of city names for filter dropdown */
    public static function cityNames(): array { ... }
}
```

**`all()` returns all 78 anchors as `CityAnchor` objects.** The array is defined inline in the method body (not loaded from file/DB at runtime). PHP will cache the opcode; no I/O on each call.

**Sample entries (representative, not exhaustive — engineer fills all 78 from the seeder coordinates):**

```php
new self('New York',      'NY',            'US', 40.7128, -74.0060, 'America/New_York'),
new self('Los Angeles',   'CA',            'US', 34.0522, -118.2437,'America/Los_Angeles'),
new self('Chicago',       'IL',            'US', 41.8781, -87.6298, 'America/Chicago'),
new self('Houston',       'TX',            'US', 29.7604, -95.3698, 'America/Chicago'),
new self('Phoenix',       'AZ',            'US', 33.4484, -112.0740,'America/Phoenix'),
new self('Philadelphia',  'PA',            'US', 39.9526, -75.1652, 'America/New_York'),
new self('San Antonio',   'TX',            'US', 29.4241, -98.4936, 'America/Chicago'),
new self('San Diego',     'CA',            'US', 32.7157, -117.1611,'America/Los_Angeles'),
new self('Dallas',        'TX',            'US', 32.7767, -96.7970, 'America/Chicago'),
new self('San Jose',      'CA',            'US', 37.3382, -121.8863,'America/Los_Angeles'),
new self('Austin',        'TX',            'US', 30.2672, -97.7431, 'America/Chicago'),
new self('San Francisco', 'CA',            'US', 37.7749, -122.4194,'America/Los_Angeles'),
new self('Seattle',       'WA',            'US', 47.6062, -122.3321,'America/Los_Angeles'),
new self('Denver',        'CO',            'US', 39.7392, -104.9903,'America/Denver'),
new self('Boston',        'MA',            'US', 42.3601, -71.0589, 'America/New_York'),
new self('Las Vegas',     'NV',            'US', 36.1699, -115.1398,'America/Los_Angeles'),
new self('Miami',         'FL',            'US', 25.7617, -80.1918, 'America/New_York'),
new self('Atlanta',       'GA',            'US', 33.7490, -84.3880, 'America/New_York'),
new self('Washington',    'DC',            'US', 38.9072, -77.0369, 'America/New_York'),
new self('Nashville',     'TN',            'US', 36.1627, -86.7816, 'America/Chicago'),
new self('Portland',      'OR',            'US', 45.5152, -122.6784,'America/Los_Angeles'),
new self('New Orleans',   'LA',            'US', 29.9511, -90.0715, 'America/Chicago'),
// Canada
new self('Toronto',       'ON',            'CA', 43.6532, -79.3832, 'America/Toronto'),
new self('Montreal',      'QC',            'CA', 45.5019, -73.5674, 'America/Toronto'),
new self('Vancouver',     'BC',            'CA', 49.2827, -123.1207,'America/Vancouver'),
new self('Calgary',       'AB',            'CA', 51.0447, -114.0719,'America/Edmonton'),
new self('Ottawa',        'ON',            'CA', 45.4215, -75.6972, 'America/Toronto'),
new self('Edmonton',      'AB',            'CA', 53.5461, -113.4938,'America/Edmonton'),
new self('Quebec City',   'QC',            'CA', 46.8139, -71.2080, 'America/Toronto'),
new self('Winnipeg',      'MB',            'CA', 49.8951, -97.1384, 'America/Winnipeg'),
// Mexico
new self('Mexico City',   'CDMX',          'MX', 19.4326, -99.1332, 'America/Mexico_City'),
new self('Guadalajara',   'Jalisco',       'MX', 20.6597, -103.3496,'America/Mexico_City'),
new self('Monterrey',     'Nuevo León',    'MX', 25.6866, -100.3161,'America/Monterrey'),
new self('Puebla',        'Puebla',        'MX', 19.0414, -98.2063, 'America/Mexico_City'),
new self('Tijuana',       'Baja California','MX',32.5149, -117.0382,'America/Tijuana'),
new self('Cancún',        'Q. Roo',        'MX', 21.1619, -86.8515, 'America/Cancun'),
new self('Mérida',        'Yucatán',       'MX', 20.9674, -89.5926, 'America/Merida'),
// Europe
new self('London',        'England',       'GB', 51.5074, -0.1278,  'Europe/London'),
new self('Paris',         'Île-de-France', 'FR', 48.8566,  2.3522,  'Europe/Paris'),
new self('Berlin',        'Berlin',        'DE', 52.5200, 13.4050,  'Europe/Berlin'),
new self('Madrid',        'Madrid',        'ES', 40.4168, -3.7038,  'Europe/Madrid'),
new self('Rome',          'Lazio',         'IT', 41.9028, 12.4964,  'Europe/Rome'),
new self('Amsterdam',     'North Holland', 'NL', 52.3676,  4.9041,  'Europe/Amsterdam'),
new self('Barcelona',     'Catalonia',     'ES', 41.3851,  2.1734,  'Europe/Madrid'),
new self('Munich',        'Bavaria',       'DE', 48.1351, 11.5820,  'Europe/Berlin'),
new self('Milan',         'Lombardy',      'IT', 45.4642,  9.1900,  'Europe/Rome'),
new self('Vienna',        'Vienna',        'AT', 48.2082, 16.3738,  'Europe/Vienna'),
new self('Prague',        'Bohemia',       'CZ', 50.0755, 14.4378,  'Europe/Prague'),
new self('Lisbon',        'Lisboa',        'PT', 38.7223, -9.1393,  'Europe/Lisbon'),
new self('Dublin',        'Leinster',      'IE', 53.3498, -6.2603,  'Europe/Dublin'),
new self('Copenhagen',    'Capital Region','DK', 55.6761, 12.5683,  'Europe/Copenhagen'),
new self('Stockholm',     'Stockholm',     'SE', 59.3293, 18.0686,  'Europe/Stockholm'),
new self('Oslo',          'Oslo',          'NO', 59.9139, 10.7522,  'Europe/Oslo'),
new self('Helsinki',      'Uusimaa',       'FI', 60.1699, 24.9384,  'Europe/Helsinki'),
new self('Brussels',      'Brussels',      'BE', 50.8503,  4.3517,  'Europe/Brussels'),
new self('Zurich',        'Zurich',        'CH', 47.3769,  8.5417,  'Europe/Zurich'),
new self('Warsaw',        'Masovia',       'PL', 52.2297, 21.0122,  'Europe/Warsaw'),
new self('Budapest',      'Budapest',      'HU', 47.4979, 19.0402,  'Europe/Budapest'),
new self('Athens',        'Attica',        'GR', 37.9838, 23.7275,  'Europe/Athens'),
new self('Lyon',          'Auvergne',      'FR', 45.7640,  4.8357,  'Europe/Paris'),
new self('Hamburg',       'Hamburg',       'DE', 53.5511,  9.9937,  'Europe/Berlin'),
new self('Manchester',    'England',       'GB', 53.4808, -2.2426,  'Europe/London'),
new self('Edinburgh',     'Scotland',      'GB', 55.9533, -3.1883,  'Europe/London'),
new self('Frankfurt',     'Hesse',         'DE', 50.1109,  8.6821,  'Europe/Berlin'),
new self('Krakow',        'Lesser Poland', 'PL', 50.0647, 19.9450,  'Europe/Warsaw'),
new self('Porto',         'Norte',         'PT', 41.1579, -8.6291,  'Europe/Lisbon'),
new self('Naples',        'Campania',      'IT', 40.8518, 14.2681,  'Europe/Rome'),
// Global hubs
new self('Tokyo',         'Kanto',         'JP', 35.6762, 139.6503, 'Asia/Tokyo'),
new self('Seoul',         'Seoul',         'KR', 37.5665, 126.9780, 'Asia/Seoul'),
new self('Singapore',     'Singapore',     'SG',  1.3521, 103.8198, 'Asia/Singapore'),
new self('Sydney',        'NSW',           'AU',-33.8688, 151.2093, 'Australia/Sydney'),
new self('Melbourne',     'Victoria',      'AU',-37.8136, 144.9631, 'Australia/Melbourne'),
new self('Dubai',         'Dubai',         'AE', 25.2048,  55.2708, 'Asia/Dubai'),
new self('São Paulo',     'SP',            'BR',-23.5505, -46.6333, 'America/Sao_Paulo'),
new self('Buenos Aires',  'BA',            'AR',-34.6037, -58.3816, 'America/Argentina/Buenos_Aires'),
```

**Engineer note:** Verify the exact count matches the 78 anchors in `EventSeeder::CITY_ANCHORS` by coordinate. The coordinates above are transcribed directly from the seeder; do not invent additional anchors. The `EventSeeder::CITY_ANCHORS` constant can remain for backward compatibility during the transition, but the engineer should update it to delegate to `CityAnchor::all()` so there is one source.

---

## 5. Services

### 5.1 `app/Services/GeocodingService.php`

**Responsibility:** Given `(float $lat, float $lng)`, return the nearest `CityAnchor` (and therefore the `location_city` string).

**Algorithm:** Euclidean distance on lat/lng (no haversine needed — the ±0.5° jitter means events are always within 0.7° of their anchor; Euclidean is exact enough for nearest-anchor matching in this bounded coordinate space).

```php
namespace App\Services;

use App\Support\CityAnchor;

final class GeocodingService
{
    /**
     * Returns the nearest CityAnchor to the given coordinates.
     */
    public function nearestAnchor(float $lat, float $lng): CityAnchor
    {
        $anchors = CityAnchor::all();
        $best = $anchors[0];
        $bestDist = PHP_FLOAT_MAX;

        foreach ($anchors as $anchor) {
            $d = ($lat - $anchor->lat) ** 2 + ($lng - $anchor->lng) ** 2;
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = $anchor;
            }
        }

        return $best;
    }

    /**
     * Returns the location_city string (bare city name) for storing in DB.
     */
    public function cityName(float $lat, float $lng): string
    {
        return $this->nearestAnchor($lat, $lng)->city;
    }
}
```

**Container binding:** Register as a singleton in `AppServiceProvider` (78-entry loop runs at most once per request lifecycle; shared across artisan commands).

### 5.2 `app/Services/TimezoneService.php`

**Responsibility:** Given a `location_city` string and a UNIX timestamp, return formatted event-local datetime strings for the frontend.

```php
namespace App\Services;

use App\Support\CityAnchor;
use Carbon\CarbonImmutable;

final class TimezoneService
{
    /** @return string  e.g. "America/New_York" — null only if city unknown */
    public function ianaTimezone(string $locationCity): ?string
    {
        return CityAnchor::timezoneMap()[$locationCity] ?? null;
    }

    /**
     * Returns the event-local formatted datetime and tz abbreviation.
     *
     * @return array{starts_at_local: string, ends_at_local: string, tz_label: string, tz_identifier: string}
     *   e.g. ['starts_at_local' => '8:00 PM', 'ends_at_local' => '11:00 PM',
     *          'tz_label' => 'CET', 'tz_identifier' => 'Europe/Paris']
     */
    public function formatEventTime(int $createdTime, ?int $endsAt, string $locationCity): array
    {
        $tz = $this->ianaTimezone($locationCity) ?? 'UTC';
        $start = CarbonImmutable::createFromTimestamp($createdTime)->setTimezone($tz);
        $end = $endsAt ? CarbonImmutable::createFromTimestamp($endsAt)->setTimezone($tz) : null;

        return [
            'starts_at_local'  => $start->format('g:i A'),        // e.g. "8:00 PM"
            'starts_at_date'   => $start->format('D, M j, Y'),    // e.g. "Tue, Jan 7, 2025"
            'ends_at_local'    => $end?->format('g:i A'),
            'tz_label'         => $start->format('T'),             // e.g. "CET", "EST"
            'tz_identifier'    => $tz,                             // e.g. "Europe/Paris"
            'utc_timestamp'    => $createdTime,                    // raw unix ts for JS fallback
        ];
    }

    /**
     * Convert a local date string (YYYY-MM-DD in event timezone) to UTC unix range.
     * Used by EventController to translate the `from`/`to` filter.
     *
     * @return array{from_ts: int|null, to_ts: int|null}
     */
    public function localDateToUtcRange(?string $fromDate, ?string $toDate, string $locationCity): array
    {
        $tz = $this->ianaTimezone($locationCity) ?? 'UTC';
        return [
            'from_ts' => $fromDate ? CarbonImmutable::parse($fromDate, $tz)->startOfDay()->utc()->timestamp : null,
            'to_ts'   => $toDate   ? CarbonImmutable::parse($toDate,   $tz)->endOfDay()->utc()->timestamp   : null,
        ];
    }
}
```

**Important note on date filter implementation:** Because each event can have a different timezone (based on its `location_city`), a single UTC timestamp window cannot be applied globally across events with different timezones. The practical approach for the filter: accept that date filtering uses a UTC conversion based on the **filter city** (when `?city=London` + `?from=2026-01-01`, compute the UTC range for London time). For the unfiltered case (no city), filter by UTC day boundaries (since all cities are within UTC-12 to UTC+14, a ±1 day buffer covers any event-local-day disagreement). Document this trade-off in `DECISIONS.md`. This matches AC-104-1 intent without per-row timezone computation in SQL.

---

## 6. Index Strategy

| Index | Columns | Type | Justification |
|---|---|---|---|
| `events_status_index` | `(status)` | B-tree | Already exists — keep |
| `events_created_time_index` | `(created_time)` | B-tree | Covers `ORDER BY created_time DESC` for unfiltered listing |
| `events_status_created_time_index` | `(status, created_time)` | B-tree | Covers `WHERE status=? ORDER BY created_time DESC` — composite allows single scan |
| `events_location_city_index` | `(location_city)` | B-tree | Covers `WHERE location_city=?` city filter |
| `event_images_event_id_sort_order_index` | `(event_id, sort_order)` | B-tree | Covers `WHERE event_id=? ORDER BY sort_order` — fully covered |

**Query patterns vs. indexes:**

1. Default listing: `WHERE status='published' ORDER BY created_time DESC` → uses `(status, created_time)` composite
2. Date filter only: `WHERE created_time BETWEEN ? AND ? ORDER BY created_time DESC` → uses `(created_time)`
3. City filter: `WHERE location_city='London' ORDER BY created_time DESC` → uses `(location_city)` then sort; if combined with status, SQLite will pick the most selective index
4. Combined: `WHERE status='published' AND location_city='London' AND created_time BETWEEN ? AND ?` → SQLite will use the most selective single index; the composite `(status, created_time)` is likely best here; location_city added as an additional filter on the result set
5. Reminder command: queries `event_registrations` + `events` by `created_time` window — covered by `(created_time)` index

**SQLite limitation:** SQLite cannot use two indexes on the same table in a single query (no index merge). The composite `(status, created_time)` is therefore critical for the most common combined query.

---

## 7. Image Storage Architecture

### 7.1 File locations

```
storage/app/public/event-images/       ← actual JPEG files (8 files)
    placeholder-01.jpg
    placeholder-02.jpg
    ...
    placeholder-08.jpg

public/storage/                        ← symlink created by artisan storage:link
    event-images/                      ← accessible via GET /storage/event-images/*.jpg
```

Placeholder files are committed to `storage/app/public/event-images/` in git. They are NOT in `public/` directly (that would bypass the storage abstraction). After `artisan storage:link`, they become accessible at `http://localhost/storage/event-images/placeholder-01.jpg`.

**Why 8 files?** 8 gives reasonable visual variety in the seeded UI, keeps the repo small (target ≤ 80 KB per file, total ≤ 640 KB repo weight increase), and the modulo arithmetic for assignment is clean (`event_index % 8 + 1`).

### 7.2 Path storage in DB

`event_images.path` stores the relative storage disk path: `event-images/placeholder-03.jpg`

**URL accessor on `EventImage` model:**

```php
public function getUrlAttribute(): string
{
    return Storage::disk('public')->url($this->path);
    // resolves to: http://localhost/storage/event-images/placeholder-03.jpg
}
```

This accessor is the ONLY place where a URL is derived. Controllers and resources always call `$image->url`, never construct the URL themselves.

### 7.3 EventImage Seeder bulk-insert logic

`EventImageSeeder` is a separate seeder called after `EventSeeder`. It reads all event IDs from the DB (as a cursor/chunk to avoid loading 1.25M UUIDs at once), and for each chunk of 4000 events builds two `event_images` rows per event.

```php
// Pseudocode — engineer implements this pattern
$placeholders = range(1, 8);
$now = now()->toDateTimeString();
$chunk = 4000;

Event::select('id')->orderBy('id')->chunk($chunk, function ($events) use ($placeholders, $now) {
    $batch = [];
    foreach ($events as $i => $event) {
        // Pick 2 distinct placeholders deterministically (consistent across re-seeds)
        $p1 = (crc32($event->id) & 0x7FFFFFFF) % 8 + 1;
        $p2 = ($p1 % 8) + 1; // next in rotation, guaranteed different
        $batch[] = [
            'event_id'   => $event->id,
            'path'       => sprintf('event-images/placeholder-%02d.jpg', $p1),
            'sort_order' => 0,
            'alt'        => 'Event image 1',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $batch[] = [
            'event_id'   => $event->id,
            'path'       => sprintf('event-images/placeholder-%02d.jpg', $p2),
            'sort_order' => 1,
            'alt'        => 'Event image 2',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::table('event_images')->insert($batch);
});
```

**Wrap in `withSeedingPragmas`** (copy the private method pattern from EventSeeder, or extract to a trait `database/seeders/Concerns/UsesSeedingPragmas.php`).

**Memory profile:** Each event contributes 2 rows × ~100 bytes in the batch array = 200 bytes/event × 4000 events = ~800 KB per chunk batch. Safe.

**Duration estimate:** 2.5M inserts in chunks of 8000 rows (4000 events × 2 images) ≈ 313 batches. With SQLite WAL PRAGMAs, should complete in 30–60 s.

---

## 8. Artisan Backfill Command

**File:** `app/Console/Commands/GeocodeEventCities.php`  
**Signature:** `events:geocode-cities {--chunk=4000 : Rows per update batch}`

**Behavior:**
1. Selects events with `location_city IS NULL` (idempotent — re-runnable)
2. Processes in chunks of 4000
3. For each chunk: compute `cityName(lat, lng)` per row via `GeocodingService`
4. Issues one `DB::table('events')->whereIn('id', $ids)->update(['location_city' => $city])` per unique city in the chunk (group by nearest city, then single UPDATE per city group — minimizes DB round-trips)
5. Reports progress to console

**Alternative grouping approach (engineer decision):** The seeder assigns one anchor per event, so each chunk of 4000 will have ~78 distinct cities but many events per city. Grouping by city and issuing one `UPDATE ... WHERE id IN (...)` per city group is more efficient than one UPDATE per row. This reduces 4000 SQL statements to ~78 per chunk.

---

## 9. Eloquent Models

### 9.1 `app/Models/EventImage.php` (new)

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EventImage extends Model
{
    protected $guarded = [];

    protected $appends = ['url'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
```

### 9.2 `app/Models/Event.php` modifications

Add to existing `Event` model:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

// In $casts:
'created_time' => 'integer',

// New relations:
public function images(): HasMany
{
    return $this->hasMany(EventImage::class)->orderBy('sort_order');
}

public function registrations(): HasMany
{
    return $this->hasMany(EventRegistration::class);
}
```

**`$casts` addition for `created_time`:** Currently `created_time` has no explicit cast (it's stored as `unsignedBigInteger` and returned as a string from SQLite in some PHP SQLite drivers). Adding `'created_time' => 'integer'` ensures consistent integer type across seeder and factory data.

---

## 10. Inertia Data Shape for Timezone Display (US-401)

The backend resolves tz at query time and passes structured data to the frontend. The `EventController::data` response shape for each event in the listing:

```typescript
interface EventListItem {
  id: string;
  type: string;
  status: string;
  created_time: number;        // raw UTC unix timestamp (kept for JS fallback)
  location_city: string | null;
  latitude: number | null;
  longitude: number | null;
  starts_at_local: string;     // e.g. "8:00 PM"
  starts_at_date: string;      // e.g. "Tue, Jan 7, 2025"
  ends_at_local: string | null;
  tz_label: string;            // e.g. "CET"
  tz_identifier: string;       // e.g. "Europe/Paris"
  cover_image_url: string | null; // first image URL, or null
}
```

**Controller responsibility:** `EventController::loadListing` selects only required scalar columns (no `payload`, no `user` eager-load for the listing), then maps through `TimezoneService::formatEventTime()` and appends `cover_image_url` via a left join or subquery on `event_images` (sort_order = 0).

**Detail view (Show):** The full payload is retained for `events.show`. The `EventController::show` method loads `images()` relation and passes the full `TimezoneService::formatEventTime()` output.

**Frontend consumption:** Wave 2 frontend components receive pre-formatted strings and display them directly. No date library computation needed in Vue for basic display. `date-fns-tz` or `luxon` is still installed (per AC-401-4) for any client-side relative time formatting ("starts in 3 days") but is NOT used for timezone conversion (that happens server-side).

---

## 11. Bug Fix (US-105)

**File:** `resources/js/pages/Events/Index.vue`, line 148  
**Change:** `"aplyFilters"` → `"applyFilters"`  
**Single character insertion, zero risk.**

---

## 12. Factory Extensions

**File:** `database/factories/EventFactory.php`

Add two factory states:

```php
/** State: event with location_city populated from a known anchor */
public function withCity(string $city = 'New York'): static
{
    return $this->state(fn () => ['location_city' => $city]);
}

/** State: event guaranteed to be in the future (for reminder tests) */
public function future(): static
{
    return $this->state(fn () => ['created_time' => now()->addDays(5)->timestamp]);
}
```

`EventImageFactory` (new):

```php
// database/factories/EventImageFactory.php
class EventImageFactory extends Factory
{
    protected $model = EventImage::class;

    public function definition(): array
    {
        return [
            'event_id'   => Event::factory(),
            'path'       => 'event-images/placeholder-0' . fake()->numberBetween(1, 8) . '.jpg',
            'sort_order' => 0,
            'alt'        => 'Event image',
        ];
    }
}
```

---

## 13. Test Coverage Plan

All new tests opt in to `RefreshDatabase` per file (matching existing pattern).

| Test file | Covers |
|---|---|
| `tests/Feature/ImageStorageTest.php` | AC-101-2 (storage URL accessible), AC-101-3 (≥2 images per factory event), AC-101-5 (relation ordered by sort_order) |
| `tests/Feature/GeocodingTest.php` | AC-102-1 (migration column exists), AC-102-2 (city derivation correct for known coordinates), AC-102-3 (no HTTP calls), AC-106-1 (all cities map to IANA tz), AC-106-2 (non-null tz for every location_city) |
| `tests/Feature/EventListingTest.php` (extend existing) | AC-103 (indexes exist — checked via `PRAGMA index_list`), AC-104-1/2/3 (date + city filters), AC-104-4 (no payload column in response) |

---

## 14. Migration Registration & Run Order

Migrations run in filename-timestamp order. New migrations must have timestamps **after** `2024_02_01_000000`:

1. `2026_06_18_000001_add_location_city_and_indexes_to_events_table.php`
2. `2026_06_18_000002_create_event_images_table.php`

Both use standard Laravel `Schema` builder and are SQLite-compatible. The in-memory test DB (`:memory:`) runs all migrations fresh on each test invocation via `RefreshDatabase` — no special handling needed.

**`DatabaseSeeder.php` changes:**
```php
// Call order (after existing EventSeeder):
$this->call(EventSeeder::class);
$this->call(EventImageSeeder::class);  // new — must come after EventSeeder
```

---

## 15. File Ownership Map (Wave Grouping)

### Group A — New files (independent, can be created in parallel)

| File | Description | Stories |
|---|---|---|
| `app/Support/CityAnchor.php` | Static dataset, tz map, city list | US-102, US-106 |
| `app/Services/GeocodingService.php` | Nearest-anchor lookup | US-102 |
| `app/Services/TimezoneService.php` | TZ lookup + datetime formatting | US-106, US-401 |
| `app/Models/EventImage.php` | New model | US-101 |
| `database/factories/EventImageFactory.php` | Test factory | US-101 |
| `database/migrations/2026_06_18_000001_*.php` | location_city + indexes | US-102, US-103 |
| `database/migrations/2026_06_18_000002_*.php` | event_images table | US-101 |
| `database/seeders/EventImageSeeder.php` | Bulk image seeding | US-101 |
| `app/Console/Commands/GeocodeEventCities.php` | Backfill command | US-102 |
| `storage/app/public/event-images/placeholder-0[1-8].jpg` | 8 placeholder JPEGs | US-101 |

### Group B — Modified files (sequential, cannot all be parallelized due to shared file)

| File | Modification | Stories | Conflict risk |
|---|---|---|---|
| `app/Models/Event.php` | Add `images()`, `registrations()` relations; cast `created_time` | US-101, US-102 | LOW — single writer |
| `app/Http/Controllers/EventController.php` | Narrow column selection; apply date/city filters | US-104 | Wave 1 only |
| `database/factories/EventFactory.php` | Add `withCity()`, `future()` states | US-102 | LOW |
| `database/seeders/DatabaseSeeder.php` | Add `EventImageSeeder::class` call | US-101 | LOW |
| `app/Providers/AppServiceProvider.php` | Register `GeocodingService` singleton | US-102 | LOW |
| `resources/js/pages/Events/Index.vue` | Fix typo on line 148 | US-105 | TRIVIAL |
| `resources/js/types/index.ts` | Add `EventImage` type, extend `Event` type | US-101, US-401 | Wave 2 prep |

### Shared-file conflicts

- `app/Models/Event.php` is touched by US-101 (images relation) and will be touched again by Wave 3 (registrations relation). The registrations relation can be added in Wave 3 without conflict if Wave 0 engineer adds a placeholder comment.
- `EventController.php` is Wave 1 only — no Wave 0 engineer should touch it.
- `EventSeeder.php` — NOT modified in Wave 0 (CityAnchor replaces its inline constant conceptually, but the seeder itself is unchanged to avoid regression risk). The engineer may optionally refactor `CITY_ANCHORS` to delegate to `CityAnchor::all()` as a follow-up, but it is NOT required for Wave 0 correctness.

---

## 16. Proposed New ADRs

| ID | Decision | Rationale |
|---|---|---|
| ADR-006 | `CityAnchor` class is the single source of truth for all 78 city definitions (coordinates, name, region, country, IANA tz). `EventSeeder::CITY_ANCHORS` is a transitional alias; new code imports `CityAnchor::all()`. | Eliminates duplication; services and seeders share identical data |
| ADR-007 | `event_images` uses bigint auto-increment PK (not UUID) | Events need URL-stable UUIDs; images are internal FK children never directly addressed |
| ADR-008 | Image seeding uses physical rows (2 per event) with recycled short path strings. No deterministic derivation. | Preserves relational model, supports AC-101-5 Eloquent relation, allows future unique images without schema change |
| ADR-009 | Date filtering converts user-supplied local date to UTC unix range server-side; SQLite index on `created_time` is preserved. When no city filter is provided, UTC day boundaries ±1 day buffer are used. | Avoids per-row timezone function in SQL; keeps index-backed query |

---

## 17. Open Questions for User

None. All decisions are derivable from the ADRs and BRS. The one previously deferred item (map library for Visual 2) remains open in `ARCHITECTURE.md` for Wave 2, not Wave 0.
