# Project State — Index (Tier 1)
_Last updated: 2026-06-18_
_Updated after: TASK-7 (Wave 4 reminder scheduler) merged — PROJECT COMPLETE_

**Events Visuals** — Laravel 13 + Vue 3 Inertia starter-kit with large seeded dataset (1.25M events, 3000 users).
**STATUS: FULLY DELIVERED** — All 7 user stories complete. Scope: two Event Visual pages (card grid + Leaflet map), local images (2+/event), DB-backed reverse-geocoded addresses, timezone-aware event-local times, date+location filtering, attendee registration, confirmation email, and 3-day + 24-hour reminder emails via hourly scheduler.

---

## Tech Stack

- **Backend**: Laravel 13 (PHP 8.3+), Laravel Fortify (auth/2FA/passkeys), Pest (tests), Pint (lint), PHPStan L7
- **Frontend**: Vue 3, Inertia.js, TypeScript, Tailwind CSS v4, Vite, reka-ui components, shadcn-vue
- **Database**: SQLite (file for seeding, in-memory for tests) | **Cache**: database | **Queue**: database
- **Filesystems**: `storage/app/public` (URL: `/storage`) for event images; `storage/app/private` for logs

---

## Build & Test Commands

| Component | Command | Count |
|---|---|---|
| **Dev** | `composer dev` (serve + queue:listen + pail + vite dev) | — |
| **Setup** | `composer setup` (install + key + migrate + seed + npm build) | — |
| **Test** | `composer test` (config:clear + pint + phpstan + artisan test) | 95 passing (Pest, in-memory SQLite) — 459 assertions, 0 PHPStan errors |
| **Lint** | `composer lint` (pint) | — |
| **Types** | `composer types:check` (phpstan L7) + `npm run types:check` (vue-tsc) | — |
| **Build** | `npm run build` (Vite + Tailwind) | — |

---

## Active Tasks

**None. All 7 user-story tasks complete.**
- TASK-1 (Wave 1: VisualOne card grid) ✓ merged
- TASK-2 (Wave 2: VisualTwo Leaflet map) ✓ merged
- TASK-3 (Wave 3: images + reverse-geocoding) ✓ merged
- TASK-4 (Wave 3: event filtering by date + location) ✓ merged
- TASK-5 (Wave 3: attendee registration UI) ✓ merged
- TASK-6 (Wave 3: confirmation email) ✓ merged
- TASK-7 (Wave 4: reminder scheduler) ✓ merged (commit 057317a)

---

## Active Constraints Summary

- **`created_time` is UNIX timestamp = event START time, not `created_at`** — queryable as event date; also in `payload.schedule.starts_at`
- **Most event data in JSON `payload` column** (name, description, venue, schedule, pricing, tags) — queries require JSON extraction or denormalization
- **No images yet** — add storage (table + seeding) for ≥2 images/event, served from `storage/app/public`
- **Lat/lng only, no addresses** — reverse-geocode or use nearest city anchor (seeder clusters ~78 real city anchors); precompute, do not geocode per-request at 1.25M scale
- **Only DB index on `status`** — `created_time` unindexed; filter by date + location requires new indexes + denormalization
- **Planted bug**: `Events/Index.vue:148` `@click="aplyFilters"` (typo; function is `applyFilters`); Filter button is no-op
- **Date + location filters not backend-implemented** — `from` plumbed but `loadListing` ignores it; no location filter at all
- **Attendees schema complete** — `event_registrations` table with `user_id`, `event_id`, status, reminder-sent tracking; unique constraint; Event::registrations() + count
- **Scheduler live** — `events:send-reminders` command runs hourly (console.php + crontab); two passes (3day/24hour), ±1h UTC window on events.created_time; chunkById(500), idempotent via `reminder_3day_sent_at` + `reminder_24hour_sent_at` stamps. PRODUCTION NOTE: server needs `* * * * * php artisan schedule:run` cron entry.
- **Reminder emails queued** — `EventReminderNotification` (Laravel Mail), $type discriminator (3day/24hour), event-local time via TimezoneService + location
- **Reminder indexes live** — composite indexes on `event_registrations`: (status, reminder_3day_sent_at) + (status, reminder_24hour_sent_at)
- **`MAIL_MAILER=array` in tests** — logged; concise MessageSent listener logs one line per email; acceptable for local dev + testing
- **Auth gated** — POST/DELETE `/events/{event}/registrations` require Fortify login; guests redirect to login
- **Payload type inconsistency** — seeder stores strings; factory stores numbers; don't assume numeric types
- **`.gitignore` updated** — transient `.claude/TASK_IN_PROGRESS` sentinel excluded

---

## Open Questions

- Timezone strategy for event display: store UTC, show in viewer's local tz, or event-local tz?
- Attendee registration: require auth or allow email capture for unauthenticated users?
- Address derivation: precompute city from nearest anchor (cheap, offline, fits seeded clustering) or call external geocoder (lat/lng → city lookup)?
- Image seeding strategy: store real photos in SQLite or reuse placeholders via a mapping table?
- Two visual layouts: what styles? (e.g. card grid + map; calendar + timeline; timeline + cards)

---

## CONVENTIONS

- **config**: use `${ENV_VAR:default}` syntax in config files; never hardcode env-specific values
- **env_vars**: when adding a new env var, update `.env.example` **and** `.env` + any config files that read it
- **shared_logic**: extract to `app/Services/`, `app/Concerns/` (PHP) or `resources/js/composables/` (Vue) if logic appears 2+ places
- **build_dir**: run `composer` commands from project root; `npm` from project root (Vite handles sub-paths)
- **build_command**: `npm run build` (Tailwind + Vite); `composer test` (PHP stack)
- **test_command**: `php artisan test` (backend) + `npm run types:check` (frontend)
- **root_cause_first**: fix filters/queries in controller (`EventController`), not in Vue views; fix email dispatch in jobs, not in templates
- **images_serving**: always serve from `storage/app/public` → URL `/storage/...`, never hotlink external URLs

---

## Backlog & Requirements

**Authoritative source**: `tasks/2-scope-and-user-stories/BUSINESS_ANALYSIS.md`
- 18 user stories across 6 implementation waves
- 5 locked architectural decisions (ADR-001 through ADR-005)
- Scope: images, location geocoding, timezone handling, attendee registration, reminders

## Tier-2 Files

- `.claude/ARCHITECTURE.md` — ADR table (decisions + rationale)
- `.claude/CONSTRAINTS.md` — full constraint detail + status
- `.claude/PATTERNS.md` — established code patterns + examples
- `.claude/HISTORY.md` — completed task log
