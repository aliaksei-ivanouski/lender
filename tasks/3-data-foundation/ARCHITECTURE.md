# Wave 0 Data Foundation Architecture

**Task:** TASK-3  
**Date:** 2026-06-18  
**Status:** REVISED R1 — 2026-06-18 — geocoding design replaced (see § R1 below)

---

## Revision R1 — Geocoding moved to DB-backed ReverseGeocoder (2026-06-18)

### What changed

The original design (DEC-003, DEC-005, §4, §5.1, §5.2) used `App\Support\CityAnchor` as the runtime geocoding engine: `GeocodingService` called `CityAnchor::all()` on every reverse-geocode request, loading all ~75 anchors into memory and scanning the full set for the nearest match. `TimezoneService` derived IANA timezone from the same static `CityAnchor::timezoneMap()` call.

This design has been **rejected on architectural grounds** and replaced with the DB-backed port+adapter described in §4-R1 and §5-R1 below.

### Why the original design was wrong

1. **Wrong boundary.** Baking an entire reference dataset into application code and scanning it in memory at request time conflates two concerns: (a) seed/reference data management and (b) geocoding as a queryable service. These are distinct responsibilities.
2. **False "small fixed set" assumption hardcoded into production code.** 75 anchors is small today. The architecture must not encode that assumption as an in-process full-scan loop. If the dataset grows to thousands of cities (or is replaced by a real API), this design requires code changes, not configuration changes.
3. **Not replaceable.** There is no seam to swap in a real external geocoder (Nominatim, Google Maps) without rewriting callers. A proper port+adapter provides that seam.

**Nuance:** The 75 anchors are the `EventSeeder`'s generation seeds, so cardinality is genuinely small today — the objection is not about current memory or speed. It is about the architectural boundary and extensibility. A DB table with 75 rows today is unchanged by adding 750 rows tomorrow; a static PHP class is not.

### What replaces it

| Original | Replacement |
|---|---|
| `CityAnchor::all()` scanned at request time | Never called at request time |
| `GeocodingService` (in-memory scan) | `ReverseGeocoder` interface + `DatabaseReverseGeocoder` adapter |
| `TimezoneService` reads `CityAnchor::timezoneMap()` | `TimezoneService` reads `city->timezone` from the resolved `City` model |
| No `cities` table | New `cities` table (seeded once from `CityAnchor` data) |
| `CityAnchor` used by services at runtime | `CityAnchor` retained but demoted to **seed-only** — never imported at request time |

### Fate of existing PR #5 (Wave 0-B)

PR #5 implements `GeocodingService` and `TimezoneService` against `CityAnchor`. It MUST NOT be merged as-is. It will be **revised** to the R1 design: `GeocodingService` is removed, `TimezoneService` is updated to accept a `City` model rather than call `CityAnchor::timezoneMap()`, and `DatabaseReverseGeocoder` is added.

---

## 1. Overview

Wave 0 establishes the data infrastructure that every subsequent wave depends on:

- `event_images` table + placeholder JPEG files + storage link
- `events.location_city` column + DB-backed reverse geocoding service
- `cities` table (seeded from `CityAnchor` data) as the queryable reference dataset
- `ReverseGeocoder` interface + `DatabaseReverseGeocoder` adapter (port+adapter pattern)
- DB indexes for date + location filtering
- Bug fix for the broken filter button

No Wave 1 or Wave 2 work can proceed until all Wave 0 migrations are run and the cities table is seeded.

---

## 2. Decisions

### DEC-001: EventImage PK — bigint auto-increment (not UUID)

`event_images` uses an auto-increment `bigint` PK. Rationale: events themselves use UUID (identity stability required for URL routing); image rows are append-only, never directly addressable by URL, and at 2.5M rows a 16-byte UUID PK wastes ~40 MB of index space. BigInt is faster for the `ORDER BY sort_order` scan and halves FK storage. No UUID required here.

### DEC-002: Image seeding strategy — physical row per event-image pair, path is a short recycled string

**Decision:** Insert a real row in `event_images` for every event-image assignment (2 rows per event = 2.5M rows). The `path` column stores a recycled short string (e.g. `event-images/placeholder-03.jpg`) — no image bytes, no blob. Total extra DB size ≈ 2.5M × ~100 bytes per row ≈ 250 MB. This is acceptable; the schema allows future unique-image assignment without redesign.

**Alternative considered — deterministic derivation (no physical rows):** Store nothing; derive `image_path = 'event-images/placeholder-' . ((crc32($event_id) % 8) + 1) . '.jpg'` at query time. Pros: zero DB growth. Cons: breaks the relational model; `sort_order` is meaningless; adding a third image requires a schema change; AC-101-5 (relation `$event->images`) is violated — the BRS explicitly requires an Eloquent relation returning `EventImage` models ordered by `sort_order`. Rejected.

**Alternative considered — single mapping table (event→image slot via join):** Store one row per placeholder file name, then a pivot. Adds complexity for no gain at this scale.

Chosen: physical rows with recycled path strings. Image seeding is done in a dedicated `EventImageSeeder` called after `EventSeeder`, using the same `CHUNK=4000` bulk-insert pattern and the seeding PRAGMAs from `EventSeeder::withSeedingPragmas`.

### DEC-003: [REVISED R1] `CityAnchor` is seed-data only; runtime geocoding uses the `cities` DB table

`App\Support\CityAnchor` is retained as the **source of truth for seeding** the `cities` table. It is imported exclusively by `CitySeeder` (one-time execution). It is NEVER imported by services, controllers, or artisan commands at request time.

Runtime geocoding queries the `cities` table via the `ReverseGeocoder` interface (see §4-R1, §5-R1). This keeps the boundary clean: reference data lives in the DB, not in application code.

**Why not a config file or JSON?** A seeder-source PHP class is still the right format for the initial data definition — type safety, no I/O at seed-import, directly diffable in git. It remains the canonical "what cities exist" definition. The DB is the runtime queryable form of that same data.

### DEC-003b: [NEW R1] `cities` DB table is the runtime geocoding reference dataset

A `cities` table (id, name, region, country, latitude DECIMAL(10,7), longitude DECIMAL(10,7), timezone VARCHAR(64), timestamps) is seeded once from `CityAnchor::all()`. All reverse-geocode lookups query this table — never the PHP class. The table starts at ~75 rows and scales to thousands with zero code changes.

### DEC-004: `location_city` stores "CityName" (bare city name, NOT "City, Country")

The BRS acceptance criterion AC-102-2 says `location_city` is set to "the name of the nearest CITY_ANCHOR (e.g. 'New York')". The tz map in AC-106-1 is also keyed by city name (e.g. `"New York" => "America/New_York"`). Storing bare city name keeps the lookup O(1) with no string parsing. The frontend can compose "New York, USA" from city + country if needed (country is returned via a separate accessor or the CityAnchor dataset).

### DEC-005: [REVISED R1] Timezone derived from resolved `City` model, not a static map

IANA timezone is stored as a column on the `cities` table (`timezone VARCHAR(64)`). When a reverse-geocode call resolves to a `City`, the timezone comes with it in the same query result — no second lookup, no static map call. `TimezoneService` accepts a resolved `City` model (or its timezone string directly) rather than calling `CityAnchor::timezoneMap()`. This keeps data consistent: the single source for both city name and timezone is the `City` row. No drift is possible because both values come from the same resolved object.

### DEC-006: `from`/`to` date filter operates on event-local date (not UTC)

Per AC-104-1/2 and D-004. SQLite does not have a timezone-aware `DATE()` function, so the backend converts the user's date filter to a UTC unix timestamp window for that city's timezone, then applies `BETWEEN` on `created_time`. This avoids per-row function calls and keeps the `created_time` index usable.

### DEC-007: Composite index `(status, created_time)` is the primary listing index

The default listing query always has `ORDER BY created_time DESC`; `status` is the most common filter. SQLite can use a composite index `(status, created_time)` for both the WHERE and ORDER BY in a single scan when status is provided. For unfiltered queries (no status), the single-column `created_time` index covers the sort. Both indexes are needed (see §6).

### DEC-008: [REVISED R1] Backfill uses port+cache at request time; ETL command uses bulk cache for throughput

The `events:geocode-cities` backfill command is a one-time ETL job. It is architecturally distinct from the request-time path:

- **Request time:** uses `ReverseGeocoder` port → `DatabaseReverseGeocoder` adapter (bbox query + Laravel cache keyed on rounded coordinates).
- **Backfill ETL:** MAY load the small `cities` reference set once into a PHP array at command start (to avoid 1.25M individual DB queries), and/or pre-warm the cache keyed by rounded coordinates. Events cluster tightly around anchors (EventSeeder jitters ±0.5°), so after the first ~75 unique rounded coordinate pairs are resolved and cached, nearly all remaining 1.25M events hit the cache. This is a deliberate, explicit ETL optimization — NOT loading-all-on-every-request. The request-time adapter is unchanged; it always goes through the DB+cache path.

**Original rationale for chunked UPDATE strategy (still applies):** A bulk `UPDATE events SET location_city = CASE ...` across 1.25M rows would lock the database. The command reads in chunks of 4000, resolves city in PHP, and issues grouped `UPDATE ... WHERE id IN (...)` per unique city per chunk (~75 cities × 1 UPDATE each = 75 statements per 4000-event chunk).

### DEC-010: [NEW R1] `ReverseGeocoder` interface is the geocoding seam

```php
namespace App\Services\Geocoding;

interface ReverseGeocoder
{
    public function reverse(float $lat, float $lng): ?City;
}
```

`DatabaseReverseGeocoder` is the default binding. A future `NominatimReverseGeocoder` or `GoogleMapsReverseGeocoder` can be swapped in `AppServiceProvider` with zero caller changes. This is the only place where the choice of geocoding backend is made.

### DEC-011: [NEW R1] `DatabaseReverseGeocoder` uses bbox prefilter + ORDER BY squared distance, never full table scan

SQLite has no native KNN or spatial index. The adapter uses:
1. **Bounding box prefilter:** `WHERE latitude BETWEEN (lat - Δ) AND (lat + Δ) AND longitude BETWEEN (lng - Δ) AND (lng + Δ)`. Δ starts at 5.0° (covers ~500 km at mid-latitudes, guaranteed to include the nearest anchor given ±0.5° event jitter). The `(latitude, longitude)` index makes this a fast index range scan.
2. **ORDER BY squared distance + LIMIT 1:** `ORDER BY ((latitude - ?) * (latitude - ?) + (longitude - ?) * (longitude - ?)) LIMIT 1`. Applies on the bbox-filtered candidate set (small).
3. **Δ widening:** If no rows match at Δ=5.0° (edge case — e.g. coordinate outside all anchors), retry at Δ=20.0°, then Δ=90.0°. In practice this path is never reached for EventSeeder-generated events.
4. **Cache:** Results are cached in the Laravel cache (`geocoder.rev:{round($lat,2)}.{round($lng,2)}`) with a long TTL (24 h). Coordinates rounded to 2 decimal places (~1 km grid), so events within the same ~1 km cell share a cache entry. With ~75 unique anchors and ±0.5° jitter, the warm cache hit rate approaches 100% after the first pass.

### DEC-009 (backfill — former DEC-008)

A bulk `UPDATE events SET location_city = CASE ...` with 78 distance calculations per row across 1.25M rows would lock the database. Instead: `php artisan events:geocode-cities` reads events in chunks of 4000, computes location_city in PHP (the nearest-anchor lookup is O(78) per event, trivially fast), and issues a bulk UPDATE per chunk with a `WHERE id IN (...)` clause. This matches the seeder pattern, respects SQLite's locking model, and can be re-run safely (idempotent: `WHERE location_city IS NULL`).

---

## 3. New Database Schema

### 3.0 Migration: `create_cities_table` [NEW R1]

**File:** `database/migrations/2026_06_18_000000_create_cities_table.php`

```php
$table->id();
$table->string('name', 100);
$table->string('region', 100)->nullable();
$table->string('country', 2);                     // ISO 3166-1 alpha-2
$table->decimal('latitude', 10, 7);
$table->decimal('longitude', 10, 7);
$table->string('timezone', 64);                   // IANA tz identifier
$table->timestamps();
$table->index(['latitude', 'longitude']);          // bbox prefilter index
```

This migration MUST run before `add_location_city_and_indexes_to_events_table` (lower timestamp). Seeded by `CitySeeder` (see §4-R1).

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

## 4. City Reference Data [REVISED R1]

### 4.1 `app/Support/CityAnchor.php` — Seed-Data Source Only

This class is the canonical definition of all city anchor entries. After R1 it is imported **only** by:
- `CitySeeder` (one-time seed of `cities` table)
- `EventSeeder` (coordinates for event generation — existing usage, unchanged)

It is **NOT** imported by any service, controller, or artisan command that runs at request time. No production code path calls `CityAnchor::all()` or `CityAnchor::timezoneMap()` after seeding is complete.

**Class contract (unchanged — seed-data role clarified):**

```php
namespace App\Support;

final class CityAnchor
{
    public function __construct(
        public readonly string $city,
        public readonly string $region,
        public readonly string $country,
        public readonly float  $lat,
        public readonly float  $lng,
        public readonly string $ianaTimezone,
    ) {}

    /** @return list<self> */
    public static function all(): array { ... }   // used by CitySeeder + EventSeeder ONLY
}
```

The `timezoneMap()` and `cityNames()` methods are **removed** — they were only needed when this class was a runtime service. City name list for the filter dropdown is now fetched from the `cities` DB table via `City::orderBy('name')->pluck('name')`.

### 4.2 `app/Models/City.php` [NEW R1]

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $guarded = [];

    protected $casts = [
        'latitude'  => 'float',
        'longitude' => 'float',
    ];
}
```

No relations needed from the City side in Wave 0. The model is a simple queryable wrapper over the `cities` table.

### 4.3 `database/seeders/CitySeeder.php` [NEW R1]

Iterates `CityAnchor::all()` and bulk-inserts into `cities`. Runs once after migrations, before `EventSeeder`. Called from `DatabaseSeeder` before `EventSeeder::class`.

```php
// Pseudocode
$rows = array_map(fn(CityAnchor $a) => [
    'name'      => $a->city,
    'region'    => $a->region,
    'country'   => $a->country,
    'latitude'  => $a->lat,
    'longitude' => $a->lng,
    'timezone'  => $a->ianaTimezone,
    'created_at'=> $now,
    'updated_at'=> $now,
], CityAnchor::all());

DB::table('cities')->insert($rows);
```

**Engineer note:** Verify the exact row count in `CitySeeder` matches `CityAnchor::all()` count by running `City::count()` post-seed.

---

## 5. Services [REVISED R1]

### 5.1 `app/Services/Geocoding/ReverseGeocoder.php` — Interface (port) [NEW R1]

```php
namespace App\Services\Geocoding;

use App\Models\City;

interface ReverseGeocoder
{
    /**
     * Returns the nearest City to the given coordinates, or null if no city
     * can be resolved (should not occur for EventSeeder-generated coordinates).
     */
    public function reverse(float $lat, float $lng): ?City;
}
```

This is the only geocoding seam. All callers (`GeocodeEventCities` command, any future service that needs city from coordinates) depend on this interface — never on a concrete class.

### 5.2 `app/Services/Geocoding/DatabaseReverseGeocoder.php` — Default adapter [NEW R1]

**Responsibility:** Implements `ReverseGeocoder` using a bounding-box SQL query against the `cities` table + Laravel cache.

**Query strategy (per DEC-011):**

```php
// Δ = 5.0 degrees initial bounding box (~500 km). Widened to 20.0 then 90.0 if no result.
$city = DB::table('cities')
    ->whereBetween('latitude',  [$lat - $delta, $lat + $delta])
    ->whereBetween('longitude', [$lng - $delta, $lng + $delta])
    ->orderByRaw('((latitude - ?) * (latitude - ?) + (longitude - ?) * (longitude - ?))',
                 [$lat, $lat, $lng, $lng])
    ->limit(1)
    ->first();
```

**Cache strategy:**

```php
$cacheKey = sprintf('geocoder.rev:%.2f.%.2f', round($lat, 2), round($lng, 2));
// TTL: 86400 seconds (24 h)
return Cache::remember($cacheKey, 86400, fn() => $this->queryNearest($lat, $lng));
```

Coordinates are rounded to 2 decimal places (~1.1 km at equator) before keying the cache. All events within the same ~1 km grid cell share one cache entry. Given EventSeeder's ±0.5° jitter around ~75 anchors, the cache warms rapidly and subsequent lookups are pure cache hits.

**Container binding (AppServiceProvider):**

```php
$this->app->bind(ReverseGeocoder::class, DatabaseReverseGeocoder::class);
```

To swap in an external geocoder: change this single binding. No caller change required.

### 5.3 `app/Services/TimezoneService.php` — REVISED R1

`TimezoneService` no longer imports `CityAnchor`. It accepts an IANA timezone string (extracted from the resolved `City` model by the caller) rather than performing a map lookup.

**Revised contract:**

```php
final class TimezoneService
{
    /**
     * Returns formatted event-local datetime strings.
     * $timezone is the IANA string from City->timezone (e.g. "America/New_York").
     *
     * @return array{starts_at_local: string, starts_at_date: string, tz_label: string,
     *               tz_identifier: string, utc_timestamp: int}
     */
    public function formatEventTime(int $unixTimestamp, string $timezone): array { ... }

    /**
     * Converts a local YYYY-MM-DD date to a UTC unix range.
     * $timezone is the IANA string from City->timezone (or 'UTC' if no city filter).
     *
     * @return array{0: int, 1: int}  [startUtcUnix, endUtcUnix]
     */
    public function localDateToUtcRange(string $localDate, string $timezone): array { ... }
}
```

The caller resolves the `City` (via `ReverseGeocoder::reverse()` or a `City::where('name', $locationCity)->first()` lookup) and passes `$city->timezone` to these methods. No static map call anywhere in the request path.

**Caller pattern (e.g. EventController):**

```php
$city = $this->reverseGeocoder->reverse($event->latitude, $event->longitude);
$tz   = $city?->timezone ?? 'UTC';
$formatted = $this->timezoneService->formatEventTime($event->created_time, $tz);
```

**Important note on date filter (unchanged from original):** Date filtering uses a UTC conversion based on the filter city's timezone. For the unfiltered case (no city), UTC day boundaries ±1 day buffer are applied. This matches AC-104-1 intent without per-row timezone SQL computation.

### 5.4 `app/Services/GeocodingService.php` — REMOVED R1

This file is deleted. The `GeocodingService` (in-memory scan via `CityAnchor::all()`) is replaced by `ReverseGeocoder` interface + `DatabaseReverseGeocoder`. PR #5 must revert/remove this file.

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

## 8. Artisan Backfill Command [REVISED R1]

**File:** `app/Console/Commands/GeocodeEventCities.php`  
**Signature:** `events:geocode-cities {--chunk=4000 : Rows per update batch}`

**Behavior:**
1. Selects events with `location_city IS NULL` (idempotent — re-runnable)
2. Processes in chunks of 4000
3. For each chunk: resolve city via the `ReverseGeocoder` port (injected via constructor), group results by city name, issue one `DB::table('events')->whereIn('id', $ids)->update(['location_city' => $city])` per unique city per chunk
4. Reports progress to console

**ETL-specific optimization (explicit, distinct from request-time path):**

The backfill command processes 1.25M events. Using the standard `DatabaseReverseGeocoder` (bbox DB query per event) would issue up to 1.25M individual queries. To avoid this, the command uses the Laravel cache, which warms rapidly:

- EventSeeder jitters each event within ±0.5° of its anchor. All events near the same anchor share cache keys (coordinates rounded to 2 decimal places).
- After the first ~75 distinct coordinate pairs are resolved and cached (one DB query each), virtually all subsequent 1.25M events hit the cache (pure memory read).
- No special ETL-only code path is needed: the standard `DatabaseReverseGeocoder` with its cache layer already achieves this behavior.

**This is NOT "loading all cities into memory on every request."** The request-time adapter issues at most one DB query per unique rounded-coordinate cache miss. The cache TTL is 24 h, so in a long-running artisan command the cache warms in the first batch and stays warm for the duration. The adapter is unchanged.

**Grouping approach (unchanged from original design):** ~75 distinct cities per 4000-event chunk → ~75 UPDATE statements per chunk (vs 4000). Reduces DB round-trips by ~98%.

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
| `tests/Feature/GeocodingTest.php` [REVISED R1] | AC-102-1 (migration column exists), AC-102-2 (city derivation correct for known coordinates via `ReverseGeocoder`), AC-102-3 (no HTTP calls — `DatabaseReverseGeocoder` uses only DB), AC-106-1 (all seeded cities have non-null timezone), AC-106-2 (reverse of known anchor coordinate returns correct city + timezone); also test cache: second call with same rounded coord does NOT issue a DB query (assert query count = 1 for 2 calls at identical rounded coords) |
| `tests/Feature/EventListingTest.php` (extend existing) | AC-103 (indexes exist — checked via `PRAGMA index_list`), AC-104-1/2/3 (date + city filters), AC-104-4 (no payload column in response) |
| `tests/Feature/CitySeederTest.php` [NEW R1] | `cities` table row count equals `CityAnchor::all()` count after seeding; every row has non-null timezone; `City` model is queryable |

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

### Group A — New files (independent, can be created in parallel) [REVISED R1]

| File | Description | Stories |
|---|---|---|
| `app/Support/CityAnchor.php` | Seed-data source only (existing, no runtime use after R1) | US-102 seed |
| `app/Models/City.php` | Eloquent model for `cities` table | US-102, US-106 |
| `app/Services/Geocoding/ReverseGeocoder.php` | Interface (port) | US-102 |
| `app/Services/Geocoding/DatabaseReverseGeocoder.php` | Default adapter (bbox SQL + cache) | US-102 |
| `app/Services/TimezoneService.php` | TZ formatting (revised: no CityAnchor import) | US-106, US-401 |
| `app/Models/EventImage.php` | New model | US-101 |
| `database/factories/EventImageFactory.php` | Test factory | US-101 |
| `database/migrations/2026_06_18_000000_create_cities_table.php` | cities table + (lat,lng) index | US-102, US-106 |
| `database/migrations/2026_06_18_000001_*.php` | location_city + indexes on events | US-102, US-103 |
| `database/migrations/2026_06_18_000002_*.php` | event_images table | US-101 |
| `database/seeders/CitySeeder.php` | Seeds cities from CityAnchor::all() | US-102 |
| `database/seeders/EventImageSeeder.php` | Bulk image seeding | US-101 |
| `app/Console/Commands/GeocodeEventCities.php` | Backfill command (uses ReverseGeocoder port) | US-102 |
| `storage/app/public/event-images/placeholder-0[1-8].jpg` | 8 placeholder JPEGs | US-101 |

**REMOVED:** `app/Services/GeocodingService.php` — deleted in R1, replaced by ReverseGeocoder port+adapter.

### Group B — Modified files (sequential, cannot all be parallelized due to shared file) [REVISED R1]

| File | Modification | Stories | Conflict risk |
|---|---|---|---|
| `app/Models/Event.php` | Add `images()`, `registrations()` relations; cast `created_time` | US-101, US-102 | LOW — single writer |
| `app/Http/Controllers/EventController.php` | Narrow column selection; apply date/city filters | US-104 | Wave 1 only |
| `database/factories/EventFactory.php` | Add `withCity()`, `future()` states | US-102 | LOW |
| `database/seeders/DatabaseSeeder.php` | Add `CitySeeder::class` (before EventSeeder) + `EventImageSeeder::class` (after EventSeeder) | US-102, US-101 | LOW |
| `app/Providers/AppServiceProvider.php` | Bind `ReverseGeocoder::class` → `DatabaseReverseGeocoder::class` | US-102 | LOW |
| `resources/js/pages/Events/Index.vue` | Fix typo on line 148 | US-105 | TRIVIAL |
| `resources/js/types/index.ts` | Add `EventImage` type, extend `Event` type | US-101, US-401 | Wave 2 prep |

### Shared-file conflicts

- `app/Models/Event.php` is touched by US-101 (images relation) and will be touched again by Wave 3 (registrations relation). The registrations relation can be added in Wave 3 without conflict if Wave 0 engineer adds a placeholder comment.
- `EventController.php` is Wave 1 only — no Wave 0 engineer should touch it.
- `EventSeeder.php` — NOT modified in Wave 0 (CityAnchor replaces its inline constant conceptually, but the seeder itself is unchanged to avoid regression risk). The engineer may optionally refactor `CITY_ANCHORS` to delegate to `CityAnchor::all()` as a follow-up, but it is NOT required for Wave 0 correctness.

---

## 16. Proposed New ADRs [REVISED R1]

| ID | Decision | Rationale |
|---|---|---|
| ADR-006 | [REVISED R1] `CityAnchor` class is the seeding source for the `cities` DB table. It is NOT a runtime service. New code that needs city/timezone data queries the `cities` table via `ReverseGeocoder` or `City` model. | Wrong boundary: baking a reference dataset into application code and scanning it in memory conflates data management with service logic; not replaceable without code changes |
| ADR-006b | [NEW R1] Geocoding uses a `ReverseGeocoder` interface (port) bound to `DatabaseReverseGeocoder` (adapter). A future external geocoder requires only a new binding in `AppServiceProvider`. | Extensibility; testability (mock the port in tests); correct separation of concern |
| ADR-006c | [NEW R1] `DatabaseReverseGeocoder` uses bbox SQL prefilter (Δ=5.0°) + ORDER BY squared distance + LIMIT 1 + Laravel cache (24 h, key = rounded-2-decimal coordinates). Never loads the full `cities` table. | SQLite has no native KNN; bbox+order-by-distance+index is pragmatic and correct; cache eliminates repeated queries for clustered event coordinates |
| ADR-007 | `event_images` uses bigint auto-increment PK (not UUID) | Events need URL-stable UUIDs; images are internal FK children never directly addressed |
| ADR-008 | Image seeding uses physical rows (2 per event) with recycled short path strings. No deterministic derivation. | Preserves relational model, supports AC-101-5 Eloquent relation, allows future unique images without schema change |
| ADR-009 | Date filtering converts user-supplied local date to UTC unix range server-side; SQLite index on `created_time` is preserved. When no city filter is provided, UTC day boundaries ±1 day buffer are used. | Avoids per-row timezone function in SQL; keeps index-backed query |

---

## 17. Open Questions for User

None. All decisions are derivable from the ADRs and BRS. The one previously deferred item (map library for Visual 2) remains open in `ARCHITECTURE.md` for Wave 2, not Wave 0.
