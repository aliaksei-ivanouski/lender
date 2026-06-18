<?php

use App\Models\City;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('renders the events listing shell without authentication', function () {
    $this->get(route('events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/Index')
            ->has('statuses', 4)
            ->where('filters.from', null)
        );
});

it('returns a json page of events with load stats for lazy loading', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    Event::factory()->for($user)->create([
        'type' => 'concert',
        'status' => 'published',
        'created_time' => 1_700_000_000,
        'latitude' => 40.7128,
        'longitude' => -74.0060,
    ]);

    $this->getJson(route('events.data'))
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'current_page',
            'last_page',
            'total',
            'stats' => ['ms', 'bytes'],
        ])
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.type', 'concert')
        ->assertJsonPath('data.0.created_time', 1_700_000_000)
        ->assertJsonPath('data.0.latitude', 40.7128)
        ->assertJsonPath('data.0.user.name', 'Ada Lovelace');
});

it('filters the data endpoint by status', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['status' => 'published']);
    Event::factory()->for($user)->create(['status' => 'cancelled']);

    $this->getJson(route('events.data', ['status' => 'cancelled']))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.status', 'cancelled');
});

it('shows an event detail page with its payload', function () {
    $user = User::factory()->create();
    $event = Event::factory()->for($user)->create([
        'payload' => ['name' => 'Global Tech Summit', 'location' => ['lat' => 1.5, 'lng' => 2.5]],
    ]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/Show')
            ->where('event.id', $event->id)
            ->where('event.payload.name', 'Global Tech Summit')
        );
});

it('renders the two visualization pages and the dashboard without authentication', function () {
    $this->get(route('events.visual1'))->assertOk();
    $this->get(route('events.visual2'))->assertOk();
    $this->get(route('dashboard'))->assertOk();
});

it('visual-1 page renders VisualOne component with shared listing props', function () {
    $this->get(route('events.visual1'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/VisualOne')
            ->has('filters')
            ->has('filters.status')
            ->has('filters.from')
            ->has('filters.to')
            ->has('filters.location_city')
            ->has('statuses', 4)
            ->has('cities')
        );
});

it('visual-2 page renders VisualTwo component with shared listing props', function () {
    $this->get(route('events.visual2'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/VisualTwo')
            ->has('filters')
            ->has('filters.status')
            ->has('filters.from')
            ->has('filters.to')
            ->has('filters.location_city')
            ->has('statuses', 4)
            ->has('cities')
        );
});

it('visual pages pass query string filters into inertia props', function () {
    $this->get(route('events.visual1', ['status' => 'published', 'from' => '2025-01-01', 'location_city' => 'London']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/VisualOne')
            ->where('filters.status', 'published')
            ->where('filters.from', '2025-01-01')
            ->where('filters.location_city', 'London')
        );
});

// ─── Index existence assertions (AC-103) ──────────────────────────────────────
//
// We query SQLite's PRAGMA index_list(events) to confirm that the Wave 0
// migrations created the required indexes on the events table.

it('events table has a created_time index (AC-103)', function () {
    /** @var list<object{seq: int, name: string, unique: int, origin: string, partial: int}> $indexes */
    $indexes = DB::select('PRAGMA index_list(events)');

    $indexNames = array_column($indexes, 'name');

    expect($indexNames)->toContain('events_created_time_index');
});

it('events table has a location_city index (AC-103)', function () {
    /** @var list<object{seq: int, name: string, unique: int, origin: string, partial: int}> $indexes */
    $indexes = DB::select('PRAGMA index_list(events)');

    $indexNames = array_column($indexes, 'name');

    expect($indexNames)->toContain('events_location_city_index');
});

// ─── US-104: date filter tests ─────────────────────────────────────────────────

it('from date filter narrows results to events on or after the given UTC date (AC-104-1)', function () {
    $user = User::factory()->create();

    // Event in January 2026 (UTC)
    Event::factory()->for($user)->create([
        'status' => 'published',
        'created_time' => mktime(12, 0, 0, 1, 15, 2026), // 2026-01-15
    ]);

    // Event in December 2025 (UTC) — should be excluded
    Event::factory()->for($user)->create([
        'status' => 'published',
        'created_time' => mktime(12, 0, 0, 12, 1, 2025), // 2025-12-01
    ]);

    $this->getJson(route('events.data', ['from' => '2026-01-01']))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.created_time', mktime(12, 0, 0, 1, 15, 2026));
});

it('to date filter narrows results to events on or before the given UTC date (AC-104-2)', function () {
    $user = User::factory()->create();

    // Event in June 2026 (UTC) — should be excluded
    Event::factory()->for($user)->create([
        'status' => 'published',
        'created_time' => mktime(12, 0, 0, 6, 1, 2026), // 2026-06-01
    ]);

    // Event in January 2026 (UTC)
    Event::factory()->for($user)->create([
        'status' => 'published',
        'created_time' => mktime(12, 0, 0, 1, 15, 2026), // 2026-01-15
    ]);

    $this->getJson(route('events.data', ['to' => '2026-03-31']))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.created_time', mktime(12, 0, 0, 1, 15, 2026));
});

it('location_city filter returns only matching-city events (AC-104-3)', function () {
    $user = User::factory()->create();

    Event::factory()->for($user)->withCity('London')->create(['status' => 'published']);
    Event::factory()->for($user)->withCity('Paris')->create(['status' => 'published']);
    Event::factory()->for($user)->withCity('London')->create(['status' => 'published']);

    $this->getJson(route('events.data', ['location_city' => 'London']))
        ->assertOk()
        ->assertJsonPath('total', 2);
});

it('combined status + date + location_city filter returns correct subset', function () {
    $user = User::factory()->create();

    // Match: London + published + in range
    Event::factory()->for($user)->withCity('London')->create([
        'status' => 'published',
        'created_time' => mktime(12, 0, 0, 3, 15, 2026),
    ]);

    // No match: wrong city
    Event::factory()->for($user)->withCity('Paris')->create([
        'status' => 'published',
        'created_time' => mktime(12, 0, 0, 3, 15, 2026),
    ]);

    // No match: wrong status
    Event::factory()->for($user)->withCity('London')->create([
        'status' => 'cancelled',
        'created_time' => mktime(12, 0, 0, 3, 15, 2026),
    ]);

    // No match: out of date range
    Event::factory()->for($user)->withCity('London')->create([
        'status' => 'published',
        'created_time' => mktime(12, 0, 0, 12, 1, 2025),
    ]);

    $this->getJson(route('events.data', [
        'status' => 'published',
        'from' => '2026-01-01',
        'to' => '2026-12-31',
        'location_city' => 'London',
    ]))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.status', 'published')
        ->assertJsonPath('data.0.location_city', 'London');
});

it('pagination envelope contains required fields with resource shape including tz fields', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->withCity('New York')->create([
        'status' => 'published',
        'created_time' => 1_700_000_000,
        'payload' => ['name' => 'NYC Event', 'description' => 'A great event'],
    ]);

    $this->seed(CitySeeder::class);

    $response = $this->getJson(route('events.data'))
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'type',
                    'status',
                    'name',
                    'location_city',
                    'created_time',
                    'starts_at_local',
                    'starts_at_date',
                    'tz_label',
                    'tz_identifier',
                    'utc_timestamp',
                    'ends_at_local',
                    'cover_image_url',
                ],
            ],
            'current_page',
            'last_page',
            'total',
            'stats' => ['ms', 'bytes'],
        ])
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.name', 'NYC Event');

    expect($response->json('current_page'))->toBe(1);
    expect($response->json('last_page'))->toBeInt();
    expect($response->json('total'))->toBe(1);
});

it('EventResource returns event-local timezone fields when city is seeded (US-401)', function () {
    $this->seed(CitySeeder::class);

    $user = User::factory()->create();
    // Paris is UTC+1/UTC+2; created_time 0 = 1970-01-01 00:00 UTC = 01:00 CET
    $event = Event::factory()->for($user)->withCity('Paris')->create([
        'created_time' => 0,
        'status' => 'published',
    ]);

    $this->getJson(route('events.data'))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.tz_identifier', 'Europe/Paris')
        ->assertJsonPath('data.0.utc_timestamp', 0);
});

it('EventResource cover_image_url resolves to the lowest sort_order image url', function () {
    $user = User::factory()->create();
    $event = Event::factory()->for($user)->create(['status' => 'published']);

    // Insert two images; lower sort_order should be selected
    EventImage::factory()->for($event)->create(['sort_order' => 5, 'path' => 'event-images/placeholder-05.jpg']);
    EventImage::factory()->for($event)->create(['sort_order' => 1, 'path' => 'event-images/placeholder-01.jpg']);

    $response = $this->getJson(route('events.data'))->assertOk();

    $coverUrl = $response->json('data.0.cover_image_url');
    expect($coverUrl)->toContain('placeholder-01.jpg');
});

it('index page includes cities list for location filter dropdown', function () {
    $this->seed(CitySeeder::class);

    $this->get(route('events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/Index')
            ->has('cities')
            ->has('filters.location_city')
            ->has('filters.to')
        );
});
