<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('renders the events listing shell without authentication', function () {
    $this->get(route('events.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/Index')
            ->has('statuses', 4)
            ->where('filters.from', '2023-01-01')
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
