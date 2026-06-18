<?php

use App\Models\Event;
use App\Models\EventImage;
use App\Models\User;
use Database\Seeders\EventImageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// DatabaseMigrations runs migrate:fresh before each test without wrapping in a
// DB transaction, which is required here because EventImageSeeder sets SQLite
// PRAGMA synchronous = OFF — a statement that SQLite rejects inside a transaction
// (the transaction wrapping used by RefreshDatabase would cause it to fail).
uses(DatabaseMigrations::class);

// ─── EventImage factory + Event->images relation ──────────────────────────────

it('Event->images() returns a collection ordered by sort_order (AC-101-5)', function () {
    $user = User::factory()->create();
    $event = Event::factory()->for($user)->create();

    EventImage::factory()->create([
        'event_id' => $event->id,
        'path' => 'event-images/2.png',
        'sort_order' => 1,
    ]);

    EventImage::factory()->create([
        'event_id' => $event->id,
        'path' => 'event-images/1.png',
        'sort_order' => 0,
    ]);

    $images = $event->images;

    expect($images)->toHaveCount(2)
        ->and($images->first()->sort_order)->toBe(0)
        ->and($images->last()->sort_order)->toBe(1);
});

it('EventImage url accessor returns a string containing the path (AC-101-2)', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $event = Event::factory()->for($user)->create();

    $image = EventImage::factory()->create([
        'event_id' => $event->id,
        'path' => 'event-images/1.png',
        'sort_order' => 0,
    ]);

    $url = $image->url;

    expect($url)->toBeString()
        ->and($url)->toContain('event-images/1.png');
});

it('Event factory with 2 EventImage children yields exactly 2 images (AC-101-5)', function () {
    Storage::fake('public');

    // Pass the relation name explicitly so Laravel resolves Event::images()
    // rather than attempting Event::eventImage() via magic naming.
    $event = Event::factory()
        ->has(EventImage::factory()->count(2), 'images')
        ->create();

    expect($event->images)->toHaveCount(2);
});

// ─── EventImageSeeder ─────────────────────────────────────────────────────────

it('EventImageSeeder creates exactly 2 event_images rows per event with sort_order 0 and 1 (AC-101-3 / AC-101-6)', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $events = Event::factory()->count(4)->for($user)->create();

    $this->seed(EventImageSeeder::class);

    // Each event should have exactly 2 images.
    foreach ($events as $event) {
        $images = $event->images()->orderBy('sort_order')->get();

        expect($images)->toHaveCount(2)
            ->and($images->first()->sort_order)->toBe(0)
            ->and($images->last()->sort_order)->toBe(1);
    }

    // Total rows in event_images must equal events × 2.
    expect(DB::table('event_images')->count())
        ->toBe($events->count() * 2);
});
