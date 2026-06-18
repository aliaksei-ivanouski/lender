# Established Coding Patterns

_Last updated: 2026-06-18_
_Updated after: TASK-3 (Wave 0, data foundation)_

---

## Geocoding & Reverse Lookup (Port/Adapter)

**Pattern**: External service behind a port interface; default adapter is database-backed.

```php
// App\Ports\ReverseGeocoder.php
interface ReverseGeocoder {
  public function findCity(float $lat, float $lng): ?City;
}

// App\Adapters\DatabaseReverseGeocoder.php — bounded search (bbox) + 24h cache
public function findCity(float $lat, float $lng): ?City {
  $cached = Cache::store('database')
    ->get("geocode:${lat}:${lng}");
  if ($cached !== null) return $cached; // null or City

  $city = City::query()
    ->whereBetween('lat', [$lat - 0.5, $lat + 0.5])
    ->whereBetween('lng', [$lng - 0.5, $lng + 0.5])
    ->orderByRaw('ABS(lat - ?) + ABS(lng - ?)', [$lat, $lng])
    ->first();

  Cache::store('database')->put("geocode:${lat}:${lng}", $city, 24 * 60);
  return $city;
}
```

**Use case**: Bind `ReverseGeocoder` interface to `DatabaseReverseGeocoder` in service container; future waves can swap in an API adapter (e.g. OpenStreetMap, Google Geocoding) without changing consumer code.

---

## Timezone-Local Event Display (Service)

**Pattern**: Centralized time formatting service using CITY_ANCHOR → IANA TZ map.

```php
// App\Services\TimezoneService.php
public function formatEventTime(int $unixTimestamp, string $cityName): array {
  $tz = CityTimezoneMap::getIanaId($cityName); // lookup, static fallback to UTC
  $dt = Carbon::createFromTimestamp($unixTimestamp, 'UTC')
    ->setTimezone($tz);

  return [
    'starts_at_local' => $dt->format('Y-m-d H:i:s'),
    'starts_at_date' => $dt->format('Y-m-d'),
    'tz_label' => $dt->getTimezoneAbbr(),
    'tz_identifier' => $tz,
    'utc_timestamp' => $unixTimestamp,
  ];
}
```

**Use case**: Always call `TimezoneService::formatEventTime($event->created_time, $event->location_city)` in controller responses and Vue templates. Never hardcode TZ conversions.

---

## Large-Table ETL: Chunked Bulk Updates

**Pattern**: For tables >1M rows, chunk by ID and perform grouped bulk UPDATE/INSERT; use SQLite pragmas only during seeding.

```php
// Example: Backfill location_city from ReverseGeocoder
php artisan event:geocode-cities

// Artisan command structure:
$this->query->lazy(chunkSize: 5000)->chunk(100, function (Collection $events) {
  $updates = [];
  foreach ($events as $event) {
    $city = $this->geocoder->findCity($event->latitude, $event->longitude);
    $updates[] = ['id' => $event->id, 'location_city' => $city?->name ?? 'Unknown'];
  }
  Event::query()->upsert($updates, ['id'], ['location_city']);
});

// Seeder only: use inline pragmas for speed
DB::statement('PRAGMA journal_mode=MEMORY');
DB::statement('PRAGMA synchronous=OFF');
// ... bulk insert logic ...
```

**Use case**: Never load 1.25M rows into memory. Never use `->get()` or `collect()` on large tables. Always `lazy()` + `chunk()`. Pragmas only in `DatabaseSeeder::up()`, not in migrations or runtime code.

---

## Types & Validation (PHPStan L7)

**Pattern**: All model relations and factory return types use generics; env() only in config files.

```php
// App\Models\Event.php
/** @return HasMany<EventImage> */
public function images(): HasMany {
  return $this->hasMany(EventImage::class);
}

/** @return BelongsTo<City> */
public function city(): BelongsTo {
  return $this->belongsTo(City::class, 'location_city', 'name');
}

// App\Database\Factories\EventFactory.php
/** @return array<string, mixed> */
public function definition(): array {
  return [
    'name' => fake()->words(3, asText: true),
    'created_time' => fake()->unixTime(),
  ];
}

// config/app.php (correct)
'debug' => (bool) env('APP_DEBUG', false),

// ❌ app/Http/Controllers/EventController.php (wrong)
// env('APP_DEBUG') — never! only in config/
```

**Use case**: Run `composer types:check` (runs `phpstan analyse --memory-limit=512M`) before marking code complete. All relation types MUST have generics; all factory methods MUST return `array<string, mixed>` or specific shape.

---

## Testing: In-Memory DB + Selective Seeding

**Pattern**: Tests use `:memory:` SQLite; do NOT seed 1.25M rows. Use factories for individual test events; call seed only when necessary (e.g. CitySeeder for geocoding tests).

```php
// tests/Feature/EventGeocodingTest.php
use RefreshDatabase;

public function test_reverse_geocoder_finds_nearest_city() {
  $this->seed(CitySeeder::class); // 78 cities only
  $event = Event::factory()
    ->create(['latitude' => 40.7128, 'longitude' => -74.0060]); // NYC

  $city = app(ReverseGeocoder::class)->findCity(40.7128, -74.0060);
  $this->assertNotNull($city);
  $this->assertEquals('New York', $city->name);
}
```

**Use case**: `RefreshDatabase` + factories are fast. Never call the full seeder in tests (even with `SEED_ROWS=100`, it causes Pest to hang). If a test needs CityAnchor data, explicitly seed `CitySeeder`. If the seeder issues inline pragmas in a transaction, wrap the test in `app(DatabaseMigrations::class)->refresh()` instead.

---

## Conventions to Establish in Later Waves

1. **Component organization**: where to place modal/filter components, layout wrappers, etc.
2. **API contracts**: resource/collection structures returned from `EventController`; attendee API shape
3. **Image serving**: how to reference `storage/app/public` files in Vue templates (URL path structure)
4. **JSON payload decoding**: where and when to `JSON.parse(event.payload)` in Vue vs. backend
5. **Email queueing**: how confirmation + reminder jobs are dispatched and structured
6. **Filtering patterns**: how filters modify query state + API calls + component re-renders
