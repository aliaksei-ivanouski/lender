# Wave 4 Architecture — Scheduler & Reminder Emails

**Task ID:** TASK-7  
**Phase:** Design  
**Status:** FINAL — ready for planning  
**Covers:** US-303, US-304, US-305 (NFR-003, NFR-005)

---

## 1. Decisions

| ID | Decision | Rationale |
|---|---|---|
| ADR-W4-001 | **Window rule: "has entered window AND not yet sent"** — 3-day: `now+71h ≤ created_time ≤ now+73h`; 24h: `now+23h ≤ created_time ≤ now+25h`. Command runs hourly; ±1h band maps exactly to the acceptance criteria windows. | Matches AC-304-1/AC-305-1 verbatim. Simpler than a sliding "has crossed threshold" check. Each hourly run covers events whose start falls in a 2-hour band centered on 72h / 24h from now. |
| ADR-W4-002 | **One notification class with a `$type` discriminator** (`'3day'` / `'24hour'`). | Single class to maintain; subject line and urgency phrasing vary by type; email body structure is identical. Mirrors the existing `RegistrationConfirmationNotification` pattern. |
| ADR-W4-003 | **Late-3-day skip rule:** if an event is ≤24h away and `reminder_3day_sent_at IS NULL`, do NOT send a late 3-day reminder. Only the 24-hour reminder fires. | Sending both back-to-back within minutes of each other would confuse the attendee. The event was registered after the 3-day window closed — that reminder is moot. |
| ADR-W4-004 | **Scheduler registration in `routes/console.php`** via `Schedule::command('events:send-reminders')->hourly()`. | `bootstrap/app.php` uses `->withRouting(commands: routes/console.php)` — the console routes file is already the designated place for Artisan scheduling in this app (Laravel 11+ style). No `app/Console/Kernel.php` exists. |
| ADR-W4-005 | **Add `schedule:work` to composer `dev` script** as a 5th concurrently process. Color: `#86efac` (green). | AC-303-1 requires `composer dev` to start the scheduler. `schedule:work` polls every minute and is the correct local-dev equivalent of the cron `schedule:run` pattern. |
| ADR-W4-006 | **Composite index on `event_registrations(status, reminder_3day_sent_at)` and `(status, reminder_24hour_sent_at)`** added via a new migration. | The query filters `event_registrations.status = 'confirmed' AND reminder_*_sent_at IS NULL`. Existing index is on `(event_id, status)` — insufficient to avoid a full scan when filtering on the sent-at nullability at scale. New composite indexes satisfy NFR-003. |
| ADR-W4-007 | **Process in chunks of 500 registrations** using `chunkById()`. | At 1.25M-event scale with many registrations, loading all matching rows at once risks OOM. `chunkById()` is cursor-safe and pagination-stable. |

---

## 2. Command Logic: `events:send-reminders`

**Class:** `App\Console\Commands\SendEventReminders`  
**Signature:** `events:send-reminders`  
**Runs:** hourly via scheduler

### Window Constants (UTC math, no timezone conversion needed)

```
NOW = Carbon::now()->timestamp  (UTC unix)

3-day window:
  low  = NOW + (72 * 3600) - 3600   =  NOW + 71h
  high = NOW + (72 * 3600) + 3600   =  NOW + 73h

24-hour window:
  low  = NOW + (24 * 3600) - 3600   =  NOW + 23h
  high = NOW + (24 * 3600) + 3600   =  NOW + 25h
```

Window math is pure UTC integer arithmetic on `events.created_time` — no timezone conversion required. The email body uses `TimezoneService` to display event-local time.

### Pseudo-logic

```
foreach threshold in ['3day', '24hour']:
    compute [low, high] unix timestamps

    EventRegistration::query()
        ->where('status', 'confirmed')
        ->whereNull("reminder_{$threshold}_sent_at")          // idempotency guard
        ->whereHas('event', fn($q) =>
            $q->where('status', 'published')
              ->whereBetween('created_time', [$low, $high])   // uses index on created_time
        )
        ->with('event.city', 'user')
        ->chunkById(500, function ($registrations) use ($threshold) {
            foreach ($registrations as $reg) {
                $reg->user->notify(new EventReminderNotification($reg->event, $threshold));
                $reg->update(["reminder_{$threshold}_sent_at" => now()]);
            }
        });
```

### Idempotency guarantee

The `whereNull("reminder_{$threshold}_sent_at")` clause is the guard. Once `update()` sets the timestamp, re-running the command finds zero rows for that registration. The update happens immediately after dispatching the notification (not in a listener), so no race window exists in the single-process scheduler model.

---

## 3. Query Shape & Index Usage

### Query intent

Find confirmed registrations whose event is published and starts within the threshold window, and where the corresponding reminder has not yet been sent.

### Index path

```
Step 1: EventRegistration table
  Filter: status = 'confirmed' AND reminder_3day_sent_at IS NULL
  Index used: NEW composite index (status, reminder_3day_sent_at)
              — narrows to the confirmed+unsent subset without a full scan.

Step 2: events table (via whereHas JOIN / subquery)
  Filter: status = 'published' AND created_time BETWEEN low AND high
  Index used: existing index on created_time (from migration 2026_06_18_000001)
              + existing index on status (from create_events_table migration)
              — SQLite planner will use created_time range scan (selective BETWEEN).
```

### Existing indexes (confirmed from migrations)

- `events.created_time` — single-column B-tree (migration `2026_06_18_000001`)
- `events.status` — single-column (from `create_events_table`)
- `event_registrations(event_id, status)` — composite (from `create_event_registrations_table`)
- `event_registrations(user_id, event_id)` — composite (from `create_event_registrations_table`)

### New indexes required (new migration)

```php
// migration: 2026_06_18_000004_add_reminder_indexes_to_event_registrations_table.php
$table->index(['status', 'reminder_3day_sent_at'],    'er_status_3day_idx');
$table->index(['status', 'reminder_24hour_sent_at'],  'er_status_24h_idx');
```

Both partial composite indexes cover the exact columns used in the WHERE clause. SQLite treats `IS NULL` as an equality predicate for index matching.

---

## 4. Notification: `EventReminderNotification`

**Class:** `App\Notifications\EventReminderNotification`  
**Pattern:** mirrors `RegistrationConfirmationNotification` exactly

```php
final class EventReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Event $event,
        public readonly string $type,  // '3day' | '24hour'
    ) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        // TimezoneService::formatEventTime($event->created_time, $city->timezone ?? 'UTC')
        // Subject: "Reminder: {name} is in 3 days" | "Reminder: {name} is tomorrow"
        // Body: event name, event-local date/time + TZ label, city/venue
        // CTA: route('events.show', $event)
    }
}
```

**Subject lines:**
- `3day`: `"Reminder: {eventName} is in 3 days"`
- `24hour`: `"Reminder: {eventName} is tomorrow"`

**Notifiable:** `User` model (already uses `Notifiable` trait via existing auth scaffold).

**Queue:** uses default `database` queue (matches `QUEUE_CONNECTION=database`). No separate queue channel needed.

---

## 5. Scheduler Registration

### `routes/console.php` addition

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('events:send-reminders')->hourly();
```

The `bootstrap/app.php` already routes `commands: __DIR__.'/../routes/console.php'` — no changes to `bootstrap/app.php` needed.

### `composer.json` dev script

Add a 5th concurrently process:

```json
"npx concurrently -c \"#93c5fd,#c4b5fd,#fb7185,#fdba74,#86efac\" \
  \"php artisan serve --host=localhost\" \
  \"php artisan queue:listen --tries=1 --timeout=0\" \
  \"php artisan pail --timeout=0\" \
  \"npx vite\" \
  \"php artisan schedule:work\" \
  --names=server,queue,logs,vite,scheduler --kill-others"
```

### Production note

In production a cron entry runs `php artisan schedule:run` every minute. `schedule:work` is a dev-only polling daemon and is NOT used in production.

---

## 6. Edge Cases & Safety

| Case | Handling |
|---|---|
| Event is `cancelled` or `sold_out` | `->where('status', 'published')` on the events subquery excludes these — no reminders sent |
| Event is in the past (`created_time < now`) | `whereBetween` lower bound is `now + 23h` — past events are never in range |
| Registration `status != 'confirmed'` | `->where('status', 'confirmed')` on event_registrations excludes cancelled/pending rows |
| Event registered after 3-day window (< 72h to go) | ADR-W4-003: 3-day reminder is skipped entirely (rows enter the table after the 3-day window closes, so `reminder_3day_sent_at` stays NULL forever but the row is never in the 3-day BETWEEN range). The 24h reminder fires normally when the event enters the 24h window. |
| Command runs twice in the same hour | `whereNull` guard ensures zero sends for already-reminded rows (NFR-005). |
| Notification dispatch fails (queue exception) | Laravel queue will retry per `retry_after` config. Since `update(sent_at)` happens inline after `notify()`, a queue exception before the update would allow a re-dispatch on the next command run — this is the correct "at-least-once with idempotency" pattern (the notification itself is queued, so the command only dispatches the job; the actual send is idempotent at the job level via the sent_at guard on re-queue). |

**Revised note on queue atomicity:** `$reg->user->notify(...)` pushes a job to the `jobs` table. The `$reg->update(sent_at)` call happens immediately after, in the same PHP loop iteration — not after the job executes. This means the sent_at is set regardless of whether the mail worker actually delivers the email. This is intentional and matches how `RegistrationConfirmationNotification` works: the guard prevents double-dispatch, not double-delivery. Acceptable for the test scope.

---

## 7. Test Specifications

All tests use `Notification::fake()`, factory-seeded in-memory SQLite, and Pest's `travelTo()` for time positioning.

| Test | Description |
|---|---|
| `sends 3-day reminder and sets reminder_3day_sent_at` | Create event with `created_time = now+72h`; confirmed registration; run command; assert `Notification::assertSentTo($user, EventReminderNotification::class, fn($n) => $n->type === '3day')` + DB `reminder_3day_sent_at` is not null |
| `3-day reminder is idempotent` | Same setup; run command twice; assert notification sent exactly once |
| `sends 24-hour reminder and sets reminder_24hour_sent_at` | Event at `now+24h`; run command; assert `24hour` type notification sent + DB column set |
| `24-hour reminder is idempotent` | Run twice; assert sent once |
| `past events receive no reminder` | Event at `now-1h`; run command; assert nothing sent |
| `draft/cancelled/sold_out events skipped` | Event at `now+72h` with `status=draft`; run command; assert nothing sent |
| `non-confirmed registration skipped` | Registration with `status=cancelled`; event at `now+72h`; run command; assert nothing sent |
| `late registration gets only 24h reminder` | Event at `now+4h` (past the 3-day window); `reminder_3day_sent_at` is null; run command; assert no 3-day notification, no 24-hour notification (not in 24h window yet). Then travel to `now+24h` window; assert 24h fires. |
| `NFR-003 index sanity` | Not a unit test — verified via `EXPLAIN QUERY PLAN` in a one-off artisan command or noted as a manual smoke-test step |

---

## 8. File List & Ownership

| File | Action | Owner | Notes |
|---|---|---|---|
| `app/Console/Commands/SendEventReminders.php` | CREATE | Engineer (new) | The artisan command |
| `app/Notifications/EventReminderNotification.php` | CREATE | Engineer (new) | Single notification class with `$type` param |
| `routes/console.php` | MODIFY | Engineer (shared) | Add `Schedule::command('events:send-reminders')->hourly()` |
| `composer.json` | MODIFY | Engineer (shared) | Add `schedule:work` to `dev` concurrently script |
| `database/migrations/2026_06_18_000004_add_reminder_indexes_to_event_registrations_table.php` | CREATE | Engineer (new) | Adds `(status, reminder_3day_sent_at)` and `(status, reminder_24hour_sent_at)` composite indexes |
| `tests/Feature/SendEventRemindersTest.php` | CREATE | Engineer (new) | All 8 test cases above |

**Shared files** (modified, may conflict with other Wave 4 parallel work):
- `routes/console.php` — also used by the scheduler registration; coordinate with any other console route changes
- `composer.json` — coordinate with any package additions in other waves

---

## 9. Open Questions

None. All design decisions are locked per the task instructions and the existing BRS/assumptions (A-003, A-006).

---

## 10. Component Summary

```
ARCHITECTURE:
  overview: >
    A single artisan command (events:send-reminders) runs hourly via the Laravel
    scheduler. It queries event_registrations for confirmed rows whose event is
    published and starts within the threshold window (±1h around 72h or 24h),
    dispatches a queued EventReminderNotification per user, then stamps the
    sent_at column for idempotency. Two new composite indexes on event_registrations
    guard NFR-003. Scheduler is registered in routes/console.php; schedule:work
    is added to composer dev.

  components:
    - name: SendEventReminders (artisan command)
      type: module
      responsibility: query + dispatch + stamp for both thresholds in one pass
      dependencies: [EventRegistration, Event, EventReminderNotification, TimezoneService]

    - name: EventReminderNotification
      type: module
      responsibility: queued mail notification with 3day/24hour type discriminator
      dependencies: [Event, TimezoneService, User (notifiable)]

    - name: Scheduler (routes/console.php)
      type: layer
      responsibility: register hourly cron trigger for events:send-reminders
      dependencies: [SendEventReminders]

  cross_cutting_concerns:
    - concern: idempotency
      approach: reminder_3day_sent_at / reminder_24hour_sent_at nullable timestamps;
                whereNull guard prevents re-dispatch; stamped immediately after notify()

    - concern: performance / NFR-003
      approach: new composite indexes (status, reminder_*_sent_at) on event_registrations;
                events filtered by indexed created_time BETWEEN range;
                chunkById(500) prevents memory exhaustion at 1.25M scale

    - concern: error handling
      approach: queue retries handle transient failures; at-least-once delivery with
                idempotency guard is acceptable for reminder emails in test scope
```
