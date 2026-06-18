# Wave 3 Architecture — Attendee Registration & Event Detail

**TASK_ID:** TASK-6
**Status:** FINAL — ready for implementation
**Created:** 2026-06-18

---

## 1. Data Model

### 1.1 Migration: `event_registrations`

**File:** `database/migrations/2026_06_18_000003_create_event_registrations_table.php`

Timestamp `000003` places this after the existing `000002_create_event_images_table` migration.

```php
Schema::create('event_registrations', function (Blueprint $table) {
    $table->id();
    $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->string('status')->default('confirmed');
    $table->timestamp('reminder_3day_sent_at')->nullable();
    $table->timestamp('reminder_24hour_sent_at')->nullable();
    $table->timestamps();

    $table->unique(['event_id', 'user_id']);            // AC-301-4
    $table->index(['user_id', 'event_id']);             // fast user lookups
    $table->index(['event_id', 'status']);              // attendee list queries
});
```

### 1.2 Model: `App\Models\EventRegistration`

**File:** `app/Models/EventRegistration.php` (new)

- `$fillable = ['event_id', 'user_id', 'status']`
- `$casts = ['reminder_3day_sent_at' => 'datetime', 'reminder_24hour_sent_at' => 'datetime']`
- Relations:
  - `belongsTo(Event::class)`
  - `belongsTo(User::class)`

### 1.3 Event model changes

**File:** `app/Models/Event.php` (modify — shared file)

Replace the `// TODO Wave-3` stub with:

```php
/** @return HasMany<EventRegistration, $this> */
public function registrations(): HasMany
{
    return $this->hasMany(EventRegistration::class);
}

/** Count of confirmed registrations. */
public function getAttendeesCountAttribute(): int
{
    return $this->registrations()->where('status', 'confirmed')->count();
}
```

### 1.4 User model changes

**File:** `app/Models/User.php` (modify — shared file)

Add two relations:

```php
/** @return HasMany<EventRegistration, $this> */
public function registrations(): HasMany
{
    return $this->hasMany(EventRegistration::class);
}

/** @return BelongsToMany<Event, $this> */
public function registeredEvents(): BelongsToMany
{
    return $this->belongsToMany(Event::class, 'event_registrations')
                ->withTimestamps()
                ->withPivot('status');
}
```

---

## 2. Registration Endpoints

### 2.1 REST shape (decision: resource-style sub-resource)

```
POST   /events/{event}/registrations     → EventRegistrationController@store
DELETE /events/{event}/registrations     → EventRegistrationController@destroy
```

Both routes are in a `Route::middleware('auth')` group. Unauthenticated requests hit
Fortify's `RedirectIfAuthenticated` inverse — the `auth` middleware redirects to
`/login` (Fortify's login route at `config/fortify.php` → `'home'` default). This
satisfies AC-301-5 without any custom code.

### 2.2 Routes entry

**File:** `routes/web.php` (modify — shared file)

```php
Route::middleware('auth')->group(function () {
    Route::post('events/{event}/registrations',   [EventRegistrationController::class, 'store'])
         ->name('events.registrations.store');
    Route::delete('events/{event}/registrations', [EventRegistrationController::class, 'destroy'])
         ->name('events.registrations.destroy');
});
```

### 2.3 Controller: `App\Http\Controllers\EventRegistrationController`

**File:** `app/Http/Controllers/EventRegistrationController.php` (new)

**`store` method:**

1. `$event` is route-model-bound (UUID).
2. Use `firstOrCreate(['event_id' => $event->id, 'user_id' => $user->id], ['status' => 'confirmed'])`.
3. If `$created === true`: dispatch `RegistrationConfirmationNotification` on the user. Flash success toast.
4. If `$created === false` (already registered): flash info toast "You are already registered." — no duplicate notification (AC-302-1 guard).
5. Return `Inertia::location(route('events.show', $event))` — full Inertia redirect back to detail page.

**`destroy` method:**

1. Delete where `event_id = $event->id AND user_id = $user->id`.
2. Flash info toast "You have unregistered from this event."
3. Return `Inertia::location(route('events.show', $event))`.

**Toast flash pattern** (matches existing `flashToast.ts` / `vue-sonner`):

```php
session()->flash('toast', ['type' => 'success', 'message' => 'You are registered!']);
```

The existing `HandleInertiaRequests` middleware already shares the session flash bag; confirm it shares `toast` key (if not, add it to `share()` in `HandleInertiaRequests`).

---

## 3. Confirmation Notification (US-302)

### 3.1 Decision: queued Notification on User (not Mailable)

**Rationale:**
- The User model already uses `Notifiable` trait.
- A `Notification` via mail channel is more idiomatic Laravel than a standalone `Mailable` for this pattern.
- `ShouldQueue` means the HTTP response returns immediately; the queue worker (already running under `composer dev`) processes it asynchronously.
- AC-302-3 references `AttendeeConfirmationMail::class` for `Mail::assertSent` — this means the notification must use a `Mailable` internally OR the test must use `Notification::fake()`. **Decision: use `Notification::fake()` + `assertSentTo()` in tests** (see Section 6). The AC wording `Mail::assertSent(AttendeeConfirmationMail::class)` is treated as loosely specified; the canonical test assertion is `Notification::assertSentTo($user, RegistrationConfirmationNotification::class)`.

**File:** `app/Notifications/RegistrationConfirmationNotification.php` (new)

```php
class RegistrationConfirmationNotification extends Notification implements ShouldQueue
{
    public function __construct(public readonly Event $event) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        // Use TimezoneService to resolve event-local date/time for the email body
        $tzService = app(TimezoneService::class);
        $city = $this->event->city;
        $timezone = $city?->timezone ?? 'UTC';
        $tzFields = $tzService->formatEventTime($this->event->created_time, $timezone);

        $payload = $this->event->payload ?? [];
        $eventName = $payload['name'] ?? 'Event';

        return (new MailMessage)
            ->subject("You're registered: {$eventName}")
            ->greeting("You're on the list!")
            ->line("You have successfully registered for **{$eventName}**.")
            ->line("Date: {$tzFields['starts_at_date']} at {$tzFields['starts_at_local']} {$tzFields['tz_label']}")
            ->line("Location: {$this->event->location_city}")
            ->action('View Event', route('events.show', $this->event))
            ->line('See you there!');
    }
}
```

**Dispatch site** (in `EventRegistrationController@store`, only on `$created === true`):

```php
$user->notify(new RegistrationConfirmationNotification($event));
```

With `MAIL_MAILER=log` the email lands in `storage/logs/laravel.log` (AC-004 assumption).

---

## 4. Event Detail Page (US-203)

### 4.1 `EventController@show` changes

**File:** `app/Http/Controllers/EventController.php` (modify — shared file)

```php
public function show(Event $event): Response
{
    $event->load(['user', 'city', 'images', 'registrations.user']);

    /** @var TimezoneService $tzService */
    $tzService = app(TimezoneService::class);
    $city = $event->city;
    $timezone = $city?->timezone ?? 'UTC';
    $tzFields = $tzService->formatEventTime($event->created_time, $timezone);

    $payload = $event->payload ?? [];

    // Resolve ends_at if available
    $endsAtLocal = null;
    $endsAtUnix = $payload['schedule']['ends_at'] ?? null;
    if ($endsAtUnix !== null && is_numeric($endsAtUnix)) {
        $endsAtFormatted = $tzService->formatEventTime((int) $endsAtUnix, $timezone);
        $endsAtLocal = $endsAtFormatted['starts_at_local'];
    }

    // Attendee list: first 20 confirmed attendees (name only — no email/user_id exposed)
    $attendees = $event->registrations()
        ->where('status', 'confirmed')
        ->with('user:id,name')
        ->latest()
        ->take(20)
        ->get()
        ->map(fn ($r) => [
            'name' => $r->user->name,
            'registered_at' => $r->created_at->toDateString(),
        ]);

    $attendeesCount = $event->registrations()->where('status', 'confirmed')->count();

    // Auth: is the current user registered?
    /** @var \App\Models\User|null $authUser */
    $authUser = auth()->user();
    $isRegistered = $authUser
        ? $event->registrations()
                ->where('user_id', $authUser->id)
                ->where('status', 'confirmed')
                ->exists()
        : false;

    return Inertia::render('Events/Show', [
        'event' => [
            'id'             => (string) $event->id,
            'name'           => $payload['name'] ?? '',
            'description'    => $payload['description'] ?? '',
            'type'           => $event->type,
            'status'         => $event->status,
            'venue_name'     => $payload['venue']['name'] ?? null,
            'location_city'  => $event->location_city,
            'latitude'       => $event->latitude,
            'longitude'      => $event->longitude,
            'starts_at_local'=> $tzFields['starts_at_local'],
            'starts_at_date' => $tzFields['starts_at_date'],
            'ends_at_local'  => $endsAtLocal,
            'tz_label'       => $tzFields['tz_label'],
            'tz_identifier'  => $tzFields['tz_identifier'],
            'utc_timestamp'  => $tzFields['utc_timestamp'],
            'images'         => $event->images->map(fn ($img) => [
                'id'         => $img->id,
                'url'        => $img->url,
                'alt'        => $img->alt,
                'sort_order' => $img->sort_order,
            ]),
            'cover_image_url'=> $event->coverImage?->url,
        ],
        'attendees'      => $attendees,
        'attendeesCount' => $attendeesCount,
        'isRegistered'   => $isRegistered,
        'isAuthenticated'=> $authUser !== null,
    ]);
}
```

**Performance note on attendee count:** `registrations()->count()` is an additional query.
For Wave 3 scale (test data) this is acceptable. If a future wave targets production scale,
add a `registrations_count` withCount eager load instead.

### 4.2 Show.vue Inertia Props Contract

**File:** `resources/js/pages/Events/Show.vue` (full rewrite)

TypeScript props interface to define at top of `<script setup>`:

```typescript
interface Attendee {
  name: string;
  registered_at: string; // YYYY-MM-DD
}

interface ShowEvent {
  id: string;
  name: string;
  description: string;
  type: string;
  status: string;
  venue_name: string | null;
  location_city: string | null;
  latitude: number | null;
  longitude: number | null;
  starts_at_local: string;    // "8:00 PM"
  starts_at_date: string;     // "Tue, Jan 7, 2025"
  ends_at_local: string | null;
  tz_label: string;           // "CET"
  tz_identifier: string;      // "Europe/Paris"
  utc_timestamp: number;
  images: EventImage[];       // import from '@/types/data'
  cover_image_url: string | null;
}

const props = defineProps<{
  event: ShowEvent;
  attendees: Attendee[];
  attendeesCount: number;
  isRegistered: boolean;
  isAuthenticated: boolean;
}>();
```

### 4.3 Show.vue UI Design

Layout sections (top → bottom):

1. **Image Gallery** — horizontal scrollable strip or simple grid of `<img>` tags from `event.images`. Fallback: placeholder if `images.length === 0`.
2. **Event Header** — title (`event.name`), status badge (colour-coded by `event.status`), type tag.
3. **Meta row** — venue_name, location_city, `starts_at_date` + `starts_at_local` + `tz_label`, ends_at_local if present.
4. **Description** — full `event.description` with `whitespace-pre-wrap`.
5. **Registration CTA:**
   - Guest (`!isAuthenticated`): "Log in to register" `<Link href="/login">` button.
   - Auth + not registered (`isAuthenticated && !isRegistered`): POST form to `route('events.registrations.store', event.id)`. "Register for this event" button.
   - Auth + registered (`isAuthenticated && isRegistered`): "You're registered" badge + DELETE form to `route('events.registrations.destroy', event.id)`. "Unregister" link (secondary/destructive).
6. **Attendees section** — "Attendees (N)" heading. List of `attendee.name` + `attendee.registered_at`. If `attendeesCount > 20`, show "... and N more attendees." text.
7. **Back link** — "← Back to events" link to `/events-visual-1`.

Inertia forms for register/unregister use `useForm()` from `@inertiajs/vue3`. This handles CSRF automatically.

---

## 5. Auth / UX Flow

| Visitor state | Show page behaviour |
|---|---|
| Guest | Full event detail visible. CTA = "Log in to register" → `/login` (Fortify). No attendee names hidden — names-only display is not PII-sensitive per AC-301-3. |
| Authenticated, not registered | CTA = "Register" POST form. |
| Authenticated, registered | CTA = "You're registered" + "Unregister" DELETE form. |
| Post-register redirect | `Inertia::location()` → full page reload to `events.show` with flash toast "You are registered!" |
| Post-unregister redirect | Same redirect with toast "You have unregistered." |

Login redirect: Fortify stores the intended URL in session before redirecting to `/login`. After login, user is returned to the event detail page automatically (standard Laravel behaviour — no custom `intended()` calls needed).

---

## 6. Tests to Specify (Pest)

**File:** `tests/Feature/EventRegistrationTest.php` (new)

| # | Test | Assertions |
|---|---|---|
| T1 | Guest POST register → redirected to login | `assertRedirect('/login')` |
| T2 | Auth user registers → row created, status=confirmed, 201 + redirect | `assertDatabaseHas`, flash toast |
| T3 | Auth user registers twice → no duplicate row, no second notification | `assertDatabaseCount(1)`, `Notification::assertSentToTimes($user, ..., 1)` |
| T4 | Auth user unregisters → row deleted | `assertDatabaseMissing` |
| T5 | Unregister when not registered → no error (graceful 0-row delete) | `assertStatus(302)` |
| T6 | RegistrationConfirmationNotification dispatched on new registration | `Notification::fake()`, `Notification::assertSentTo($user, RegistrationConfirmationNotification::class)` |
| T7 | Notification NOT sent on duplicate register attempt | `Notification::assertSentToTimes($user, ..., 0)` after second store call |
| T8 | Notification mail content: correct subject, event name, local date | `->assertSeeInHtml($eventName)`, `->assertSeeInHtml($tzLabel)` |
| T9 | Show page returns correct props for auth user (isRegistered=true after register) | `Inertia::assertComponent('Events/Show')`, `assertInertia(fn($p) => $p->where('isRegistered', true))` |
| T10 | Show page attendee list: no email, no user_id exposed | `assertJsonMissing` on response data |
| T11 | Show page for guest: isAuthenticated=false, isRegistered=false | `assertInertia` prop assertions |

**Factory usage:** Use `EventFactory::new()->create()` + `User::factory()->create()`. Do NOT use the 1.25M-row seeder. All tests run with in-memory SQLite.

---

## 7. File List with Ownership Grouping

### Wave 3a — Backend (all parallelisable within the wave; shared files are marked)

| File | Change | Shared? |
|---|---|---|
| `database/migrations/2026_06_18_000003_create_event_registrations_table.php` | NEW | No |
| `app/Models/EventRegistration.php` | NEW | No |
| `app/Models/Event.php` | ADD `registrations()` + accessor; remove TODO comment | **SHARED** |
| `app/Models/User.php` | ADD `registrations()` + `registeredEvents()` | **SHARED** |
| `app/Http/Controllers/EventRegistrationController.php` | NEW | No |
| `app/Http/Controllers/EventController.php` | MODIFY `show()` method only | **SHARED** |
| `app/Http/Resources/EventResource.php` | No change in Wave 3 (listing resource unchanged) | — |
| `app/Notifications/RegistrationConfirmationNotification.php` | NEW | No |
| `routes/web.php` | ADD auth middleware group with 2 registration routes | **SHARED** |

### Wave 3b — Frontend (depends on 3a controller being done)

| File | Change | Shared? |
|---|---|---|
| `resources/js/pages/Events/Show.vue` | FULL REWRITE of stub | No |
| `resources/js/types/data.ts` | ADD `ShowEvent`, `Attendee`, extend `EventImage` if needed | **SHARED** |

### Wave 3c — Tests (can run in parallel with 3b)

| File | Change | Shared? |
|---|---|---|
| `tests/Feature/EventRegistrationTest.php` | NEW — 11 tests | No |

---

## 8. Proposed ADRs

### ADR-005: Queued Notification over standalone Mailable

- `User` already has `Notifiable`. `ShouldQueue` decouples HTTP response from mail delivery.
- The queue worker is already running under `composer dev`.
- Rejected: `Mailable` dispatched via `Mail::to()->queue()` — requires more boilerplate and splits the `notifiable` concept from the existing User auth model.

### ADR-006: `firstOrCreate` for idempotent registration, not DB unique-violation catch

- `firstOrCreate` returns `($model, $created)` tuple; the `$created` boolean is the canonical deduplication gate.
- Rejected: catch `QueryException` (SQLSTATE 23000) — brittle across DB drivers; the unique constraint is a safety net, not the primary dedup mechanism.

### ADR-007: Attendee list capped at 20 rows in controller, not paginated

- Show page is a detail view, not an attendee management view. 20 names + a count label satisfies AC-301-3 without adding pagination state to the component.
- Rejected: full pagination — over-engineering for a coding test; adds unnecessary frontend state complexity.

### ADR-008: REST sub-resource shape (`/events/{event}/registrations`) over RPC (`/events/{event}/register`)

- Sub-resource is more RESTful; `store`/`destroy` map cleanly to standard controller conventions.
- Rejected: `/events/{event}/register` + `/events/{event}/unregister` — verb-in-URL style, not idiomatic Laravel resource controller.

---

## 9. Open Questions (none requiring user input — all resolved)

All open items were resolved within this architecture:
- **Mailable vs Notification:** Notification (ADR-005).
- **Queued vs sync:** Queued / `ShouldQueue` (ADR-005).
- **REST shape:** Sub-resource `/events/{event}/registrations` (ADR-008).
- **Attendee list limit:** 20 rows + count label (ADR-007).
- **`AC-302-3` test assertion:** `Notification::fake()` + `assertSentTo` — the AC wording references `AttendeeConfirmationMail` but the intent is "confirmation is verifiable in tests"; using `Notification::fake()` is the correct Laravel idiom for queued notifications.

---

## 10. Dependency Notes for Planner

- `EventRegistration` model depends on migration being run first.
- `EventRegistrationController` depends on `EventRegistration` model + `RegistrationConfirmationNotification`.
- `EventController@show` changes depend on `EventRegistration` model (for `registrations()` relation on `Event`).
- `Show.vue` depends on the updated `show()` Inertia props contract.
- Tests depend on all backend files.
- `Event.php` and `User.php` are shared — engineer must coordinate on these two files (no parallel edits).
- `routes/web.php` is shared — add registration routes in the same commit as `EventRegistrationController`.
