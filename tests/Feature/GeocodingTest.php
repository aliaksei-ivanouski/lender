<?php

use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Services\Geocoding\DatabaseReverseGeocoder;
use App\Services\Geocoding\ReverseGeocoder;
use App\Services\TimezoneService;
use App\Support\CityAnchor;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── CitySeeder ────────────────────────────────────────────────────────────────

it('CitySeeder seeds exactly 75 rows into the cities table (AC-106-1)', function () {
    $this->seed(CitySeeder::class);

    $expected = count(CityAnchor::all());

    expect(City::count())->toBe($expected)
        ->and($expected)->toBe(75);
});

it('every seeded city has a non-null non-empty timezone (AC-106-1)', function () {
    $this->seed(CitySeeder::class);

    $citiesWithoutTimezone = City::query()
        ->whereNull('timezone')
        ->orWhere('timezone', '')
        ->count();

    expect($citiesWithoutTimezone)->toBe(0);
});

// ─── DatabaseReverseGeocoder::reverse() ────────────────────────────────────────

it('resolves New York (40.71, -74.0) to "New York" / America/New_York (AC-102-2)', function () {
    $this->seed(CitySeeder::class);

    /** @var ReverseGeocoder $geocoder */
    $geocoder = app(ReverseGeocoder::class);

    $city = $geocoder->reverse(40.71, -74.0);

    expect($city)->not()->toBeNull()
        ->and($city->name)->toBe('New York')
        ->and($city->timezone)->toBe('America/New_York');
});

it('resolves London (51.5, -0.13) to "London" / Europe/London (AC-102-2)', function () {
    $this->seed(CitySeeder::class);

    /** @var ReverseGeocoder $geocoder */
    $geocoder = app(ReverseGeocoder::class);

    $city = $geocoder->reverse(51.5, -0.13);

    expect($city)->not()->toBeNull()
        ->and($city->name)->toBe('London')
        ->and($city->timezone)->toBe('Europe/London');
});

it('resolves Tokyo (35.68, 139.65) to "Tokyo" / Asia/Tokyo (AC-102-2)', function () {
    $this->seed(CitySeeder::class);

    /** @var ReverseGeocoder $geocoder */
    $geocoder = app(ReverseGeocoder::class);

    $city = $geocoder->reverse(35.68, 139.65);

    expect($city)->not()->toBeNull()
        ->and($city->name)->toBe('Tokyo')
        ->and($city->timezone)->toBe('Asia/Tokyo');
});

it('resolves Sydney (-33.87, 151.21) to "Sydney" / Australia/Sydney (AC-102-2)', function () {
    $this->seed(CitySeeder::class);

    /** @var ReverseGeocoder $geocoder */
    $geocoder = app(ReverseGeocoder::class);

    $city = $geocoder->reverse(-33.87, 151.21);

    expect($city)->not()->toBeNull()
        ->and($city->name)->toBe('Sydney')
        ->and($city->timezone)->toBe('Australia/Sydney');
});

it('resolves São Paulo (-23.55, -46.63) to "São Paulo" / America/Sao_Paulo (AC-102-2)', function () {
    $this->seed(CitySeeder::class);

    /** @var ReverseGeocoder $geocoder */
    $geocoder = app(ReverseGeocoder::class);

    $city = $geocoder->reverse(-23.55, -46.63);

    expect($city)->not()->toBeNull()
        ->and($city->name)->toBe('São Paulo')
        ->and($city->timezone)->toBe('America/Sao_Paulo');
});

// ─── Cache behaviour ───────────────────────────────────────────────────────────
//
// The adapter caches the city ID by key geocoder.rev:{lat:.2f}.{lng:.2f}.
// Two calls with the same rounded coordinates should issue the bbox query only
// ONCE. We measure this by counting DB queries between the two calls.
//
// Method: enable query log, make call 1 (cold), flush the log, make call 2
// (same rounded coords — cache hit), count the resulting queries.  After a
// successful first call the cache entry is stored; the second call finds it
// immediately and triggers one additional City::find() but NO bbox query to
// the `cities` table. We assert that zero queries hit `cities` on the second call.

it('two reverse() calls at the same rounded coords issue the bbox query only once (AC-102-cache)', function () {
    $this->seed(CitySeeder::class);

    /** @var ReverseGeocoder $geocoder */
    $geocoder = app(ReverseGeocoder::class);

    // Warm the cache with the first call.
    $city1 = $geocoder->reverse(40.71, -74.00);
    expect($city1)->not()->toBeNull();

    // Enable query log after the first call so we only capture queries for
    // the second call.
    DB::enableQueryLog();

    // Second call — same coordinates (rounds to 40.71 / -74.00 identical key).
    $city2 = $geocoder->reverse(40.71, -74.00);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // No query to the `cities` table should have been issued; the only
    // allowed query is the City::find($cachedId) lookup by primary key.
    $citiesTableQueries = array_filter(
        $queries,
        fn (array $q) => str_contains($q['query'], 'from "cities"')
                      && str_contains($q['query'], 'between'),
    );

    expect($citiesTableQueries)->toHaveCount(0)
        ->and($city2)->not()->toBeNull()
        ->and($city2->name)->toBe($city1->name);
});

// ─── Δ-widening ────────────────────────────────────────────────────────────────
//
// A coordinate far from all anchors (mid-Atlantic, 0.0 / -30.0) is outside
// every 5° bbox but within the 90° widened search.  The adapter must still
// return a non-null nearest City.

it('Δ-widening returns a non-null City for a coord far from all anchors (AC-102-2)', function () {
    $this->seed(CitySeeder::class);

    /** @var ReverseGeocoder $geocoder */
    $geocoder = app(ReverseGeocoder::class);

    // Mid-Atlantic: no anchor is within ±5° of this point.
    $city = $geocoder->reverse(0.0, -30.0);

    expect($city)->not()->toBeNull();
});

// ─── TimezoneService ───────────────────────────────────────────────────────────

it('formatEventTime returns the 5 required keys with correct tz_identifier (AC-106-2)', function () {
    $service = app(TimezoneService::class);

    $ts = mktime(20, 0, 0, 6, 15, 2025); // some fixed UTC timestamp
    $result = $service->formatEventTime((int) $ts, 'America/New_York');

    expect($result)->toHaveKeys(['starts_at_local', 'starts_at_date', 'tz_label', 'tz_identifier', 'utc_timestamp'])
        ->and($result['tz_identifier'])->toBe('America/New_York')
        ->and($result['utc_timestamp'])->toBe((int) $ts);
});

it('localDateToUtcRange returns an ordered [start, end] int pair for a known timezone (AC-106-2)', function () {
    $service = app(TimezoneService::class);

    [$start, $end] = $service->localDateToUtcRange('2025-06-15', 'America/New_York');

    expect($start)->toBeInt()
        ->and($end)->toBeInt()
        ->and($start)->toBeLessThan($end);

    // The range must span exactly one calendar day (86400 seconds).
    expect($end - $start)->toBe(86399);
});

it('localDateToUtcRange with null timezone returns a wider buffered range (AC-106-2)', function () {
    $service = app(TimezoneService::class);

    [$tzStart, $tzEnd] = $service->localDateToUtcRange('2025-06-15', 'America/New_York');
    [$nullStart, $nullEnd] = $service->localDateToUtcRange('2025-06-15', null);

    // Null-tz path adds ±1 day buffer — the range is wider on both sides.
    expect($nullStart)->toBeLessThan($tzStart)
        ->and($nullEnd)->toBeGreaterThan($tzEnd);
});

// ─── GeocodeEventCities command ────────────────────────────────────────────────

it('events:geocode-cities sets location_city on all null-city events (AC-102-4)', function () {
    $this->seed(CitySeeder::class);

    $user = User::factory()->create();

    // Create 5 events with explicit coordinates near New York (location_city is null by default).
    Event::factory()->count(5)->for($user)->create([
        'latitude' => 40.71,
        'longitude' => -74.00,
        'location_city' => null,
    ]);

    expect(Event::whereNull('location_city')->count())->toBe(5);

    $this->artisan('events:geocode-cities')->assertSuccessful();

    expect(Event::whereNull('location_city')->count())->toBe(0);

    // All resolved events should map to "New York".
    expect(Event::where('location_city', 'New York')->count())->toBe(5);
});

it('events:geocode-cities is idempotent — second run is a no-op (AC-102-4)', function () {
    $this->seed(CitySeeder::class);

    $user = User::factory()->create();

    Event::factory()->count(3)->for($user)->create([
        'latitude' => 40.71,
        'longitude' => -74.00,
        'location_city' => null,
    ]);

    // First run — geocodes events.
    $this->artisan('events:geocode-cities')->assertSuccessful();
    expect(Event::whereNull('location_city')->count())->toBe(0);

    // Second run — nothing to do.
    $this->artisan('events:geocode-cities')
        ->assertSuccessful()
        ->expectsOutputToContain('nothing to do');
});
