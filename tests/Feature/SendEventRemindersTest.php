<?php

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// ─── T1: 3-day reminder is sent and reminder_3day_sent_at is stamped ──────────

it('sends 3-day reminder and sets reminder_3day_sent_at', function () {
    Notification::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'published',
        'created_time' => now()->addHours(72)->timestamp,
    ]);
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
    ]);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Notification::assertSentTo(
        $user,
        EventReminderNotification::class,
        fn (EventReminderNotification $n) => $n->type === '3day' && $n->event->id === $event->id
    );

    $this->assertDatabaseMissing('event_registrations', [
        'event_id' => $event->id,
        'user_id' => $user->id,
        'reminder_3day_sent_at' => null,
    ]);
});

// ─── T2: 3-day reminder is idempotent ─────────────────────────────────────────

it('3-day reminder is idempotent — running command twice sends notification once', function () {
    Notification::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'published',
        'created_time' => now()->addHours(72)->timestamp,
    ]);
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
    ]);

    $this->artisan('events:send-reminders')->assertSuccessful();
    $this->artisan('events:send-reminders')->assertSuccessful();

    Notification::assertSentToTimes($user, EventReminderNotification::class, 1);
});

// ─── T3: 24-hour reminder is sent and reminder_24hour_sent_at is stamped ──────

it('sends 24-hour reminder and sets reminder_24hour_sent_at', function () {
    Notification::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'published',
        'created_time' => now()->addHours(24)->timestamp,
    ]);
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
    ]);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Notification::assertSentTo(
        $user,
        EventReminderNotification::class,
        fn (EventReminderNotification $n) => $n->type === '24hour' && $n->event->id === $event->id
    );

    $this->assertDatabaseMissing('event_registrations', [
        'event_id' => $event->id,
        'user_id' => $user->id,
        'reminder_24hour_sent_at' => null,
    ]);
});

// ─── T4: 24-hour reminder is idempotent ───────────────────────────────────────

it('24-hour reminder is idempotent — running command twice sends notification once', function () {
    Notification::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'published',
        'created_time' => now()->addHours(24)->timestamp,
    ]);
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
    ]);

    $this->artisan('events:send-reminders')->assertSuccessful();
    $this->artisan('events:send-reminders')->assertSuccessful();

    Notification::assertSentToTimes($user, EventReminderNotification::class, 1);
});

// ─── T5: Past events receive no reminder ──────────────────────────────────────

it('past events receive no reminder', function () {
    Notification::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'published',
        'created_time' => now()->subHour()->timestamp,
    ]);
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
    ]);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

// ─── T6: Events far in the future receive no reminder ─────────────────────────

it('events too far in the future receive no reminder', function () {
    Notification::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'published',
        'created_time' => now()->addDays(10)->timestamp,
    ]);
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
    ]);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

// ─── T7: Draft / cancelled events are skipped ─────────────────────────────────

it('draft and cancelled events are skipped', function () {
    Notification::fake();

    $user = User::factory()->create();

    foreach (['draft', 'cancelled'] as $status) {
        $event = Event::factory()->create([
            'status' => $status,
            'created_time' => now()->addHours(72)->timestamp,
        ]);
        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);
    }

    $this->artisan('events:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

// ─── T8: Non-confirmed registrations are skipped ──────────────────────────────

it('non-confirmed registrations are skipped', function () {
    Notification::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'published',
        'created_time' => now()->addHours(72)->timestamp,
    ]);
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'cancelled',
    ]);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

// ─── T9: Late registration — only 24h reminder fires in its own window ────────

it('a late registration with null reminder_3day_sent_at only fires the 24h reminder in its window', function () {
    Notification::fake();

    $user = User::factory()->create();

    // Event is only 4h away — past the 3-day window; the 24h window starts at now+23h.
    $event = Event::factory()->create([
        'status' => 'published',
        'created_time' => now()->addHours(4)->timestamp,
    ]);
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
        'reminder_3day_sent_at' => null,
    ]);

    // Running now — neither 3day nor 24h window matches a 4h-out event.
    $this->artisan('events:send-reminders')->assertSuccessful();
    Notification::assertNothingSent();

    // Travel forward so the event is now ~24h out (lands in the 24h window).
    $this->travelTo(now()->subHours(20));

    // Re-create event at now+24h relative to the new "now".
    $event->update(['created_time' => now()->addHours(24)->timestamp]);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Notification::assertSentTo(
        $user,
        EventReminderNotification::class,
        fn (EventReminderNotification $n) => $n->type === '24hour'
    );

    // No 3-day reminder was ever sent.
    $this->assertDatabaseHas('event_registrations', [
        'event_id' => $event->id,
        'user_id' => $user->id,
        'reminder_3day_sent_at' => null,
    ]);
});
