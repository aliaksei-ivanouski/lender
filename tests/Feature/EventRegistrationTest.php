<?php

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\RegistrationConfirmationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// ─── T1: Guest cannot register ────────────────────────────────────────────────

it('guest POST to registrations is redirected to login without creating a row', function () {
    $event = Event::factory()->create();

    $response = $this->post(route('events.registrations.store', $event));

    $response->assertRedirect(route('login'));
    $this->assertDatabaseCount('event_registrations', 0);
});

// ─── T2: Authenticated user registers ─────────────────────────────────────────

it('authenticated user can register for an event', function () {
    Notification::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('events.registrations.store', $event));

    $response->assertRedirect(route('events.show', $event));

    $this->assertDatabaseHas('event_registrations', [
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
    ]);

    Notification::assertSentTo($user, RegistrationConfirmationNotification::class);
});

// ─── T3: Deduplication — second register creates no extra row ─────────────────

it('registering twice creates only one row and sends the notification only once', function () {
    Notification::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user)->post(route('events.registrations.store', $event));
    $this->actingAs($user)->post(route('events.registrations.store', $event));

    $this->assertDatabaseCount('event_registrations', 1);

    Notification::assertSentToTimes($user, RegistrationConfirmationNotification::class, 1);
});

// ─── T4: Unregister removes the row ───────────────────────────────────────────

it('authenticated user can unregister from an event and the row is deleted', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
    ]);

    $response = $this->actingAs($user)
        ->delete(route('events.registrations.destroy', $event));

    $response->assertRedirect(route('events.show', $event));

    $this->assertDatabaseMissing('event_registrations', [
        'event_id' => $event->id,
        'user_id' => $user->id,
    ]);
});

// ─── T5: Unregister when not registered is graceful ───────────────────────────

it('unregistering when not registered returns a redirect without error', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $response = $this->actingAs($user)
        ->delete(route('events.registrations.destroy', $event));

    $response->assertRedirect();
    $this->assertDatabaseCount('event_registrations', 0);
});

// ─── T6: Notification is dispatched only on new registration ──────────────────

it('RegistrationConfirmationNotification is sent to the user on first registration', function () {
    Notification::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user)->post(route('events.registrations.store', $event));

    Notification::assertSentTo(
        $user,
        RegistrationConfirmationNotification::class,
        function (RegistrationConfirmationNotification $notification) use ($event): bool {
            return $notification->event->id === $event->id;
        }
    );
});

// ─── T7: Notification is NOT re-sent on duplicate register ────────────────────

it('no notification is sent when user tries to register a second time', function () {
    Notification::fake();

    $user = User::factory()->create();
    $event = Event::factory()->create();

    // Pre-create the registration so the second POST hits the "already registered" branch.
    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
    ]);

    $this->actingAs($user)->post(route('events.registrations.store', $event));

    Notification::assertSentToTimes($user, RegistrationConfirmationNotification::class, 0);
});

// ─── T8: Notification mail subject and body contain the event name ────────────

it('RegistrationConfirmationNotification mail subject and intro lines contain the event name', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'payload' => [
            'name' => 'Laravel Summit 2026',
            'venue' => ['name' => 'Test Venue', 'capacity' => 500],
            'schedule' => ['starts_at' => 1_700_000_000, 'ends_at' => 1_700_007_200],
        ],
        'created_time' => 1_700_000_000,
    ]);

    $notification = new RegistrationConfirmationNotification($event);
    $mail = $notification->toMail($user);

    // The subject is set as a string property on MailMessage (extends SimpleMessage).
    expect($mail->subject)->toContain('Laravel Summit 2026');

    // The intro lines (set via ->line()) must also reference the event name.
    $introText = implode(' ', $mail->introLines);
    expect($introText)->toContain('Laravel Summit 2026');
});

// ─── T9: Show page props for authenticated and registered user ─────────────────

it('events.show returns correct Inertia props for a registered authenticated user', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
    ]);

    $this->actingAs($user)
        ->get(route('events.show', $event))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/Show')
            ->has('event')
            ->has('attendees')
            ->has('attendeesCount')
            ->where('isRegistered', true)
            ->where('isAuthenticated', true)
        );
});

// ─── T10: Attendees prop does not expose email or user_id ────────────────────

it('attendees prop does not expose email or user_id of registrants', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    EventRegistration::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'confirmed',
    ]);

    $this->actingAs($user)
        ->get(route('events.show', $event))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/Show')
            ->has('attendees', 1, fn ($attendee) => $attendee
                ->has('name')
                ->has('registered_at')
                ->missing('email')
                ->missing('user_id')
            )
        );
});

// ─── T11: Show page for guest has correct auth/registration flags ─────────────

it('events.show for a guest returns isAuthenticated=false and isRegistered=false', function () {
    $event = Event::factory()->create();

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/Show')
            ->where('isAuthenticated', false)
            ->where('isRegistered', false)
        );
});

// ─── T12: attendeesCount reflects total; attendees list is capped at 20 ───────

it('attendeesCount is the true total even when more than 20 users are registered', function () {
    $owner = User::factory()->create();
    $event = Event::factory()->for($owner)->create();

    // Create 22 distinct registrations.
    $registrants = User::factory()->count(22)->create();
    foreach ($registrants as $registrant) {
        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
            'status' => 'confirmed',
        ]);
    }

    $this->actingAs($owner)
        ->get(route('events.show', $event))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Events/Show')
            ->where('attendeesCount', 22)
            ->has('attendees', 20)
        );
});
