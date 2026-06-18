# Business Requirements Specification — Event Visuals (LHP Coding Test)

**BRS version:** 1.1  
**Created:** 2026-06-18  
**Status:** FINAL — ready for planning  
**Task ID:** TASK-2

---

## 1. Problem Statement

The application holds 1.25 million events in a seeded SQLite database but currently offers no
usable browsing experience: the only event-facing UI is a raw debug table, two completely empty
visual page stubs, and a broken filter button. Events carry no images, no human-readable
addresses, and there is no mechanism for a person to register attendance or receive any
communication about an event. The cost of not addressing this is a codebase that fails the
coding test entirely: the two visual pages, attendee registration, and all email flows are the
primary evaluated deliverables.

---

## 2. Business Context

This is a take-home coding test. The repository is a Laravel 13 + Vue 3 + Inertia.js
starter-kit pre-seeded at production-like scale (~1.25 M events, ~2.5 GB SQLite). The
evaluator expects: (a) two visually distinct event-browsing pages, (b) end-to-end image
support, (c) human-readable addresses derived from lat/lng, (d) sensible timezone-aware
date/time display, (e) date + location filtering that actually works, (f) an attendee
registration flow with confirmation email, and (g) scheduled reminder emails at 3-day and
24-hour intervals. The existing auth/settings scaffold (Fortify, 2FA, passkeys,
`settings/*` routes) is NOT part of this test and must not be modified.

---

## 3. Scope

### 3.1 In-Scope

1. Data foundation: `event_images` table + 5–10 small local placeholder JPEG files committed to repo + `artisan storage:link` setup + seeder assigns 2+ images per event via bulk chunked inserts
2. Data foundation: `location_city` denormalized column (nearest CITY_ANCHOR, offline) + migration + bulk population command
3. Data foundation: `timezone` derivation from CITY_ANCHOR via static IANA timezone map (~78 entries) — stored or derived per event
4. Data foundation: `created_time` index + `location_city` index
5. Backend: fix `loadListing` — apply `from`/`to` date filter (event-local date basis), add city filter, select only required columns
6. Backend: fix planted bug — `aplyFilters` → `applyFilters` in `Events/Index.vue:148`
7. Event Visual 1 — responsive CARD GRID layout: all required fields, date + location filters, tasteful animations, Tailwind styling
8. Event Visual 2 — interactive MAP with pins layout: lat/lng pins, same data + filters, tasteful animations, Tailwind styling
9. Event detail enhancements (if a detail/show page is used by visuals): images carousel/gallery, human-readable city, event-local timezone datetime
10. Attendee registration: `attendees` table (`user_id` FK, `event_id` FK, unique constraint), model, authenticated registration endpoint, attendee list display
11. Confirmation email: queued Mailable dispatched on attendee creation (confirmation notification — not a double-opt-in verification link)
12. Reminder emails: scheduled command (`SendEventReminders`), 3-day window, 24-hour window, idempotent dispatch, queue worker integration
13. Scheduler setup: add `schedule:work` process to `composer dev`; define schedule in `routes/console.php` or `app/Console/Kernel`
14. Pest tests for all new features (images, geocoding, attendees, confirmation email, reminders)
15. `DECISIONS.md` or equivalent short note on decisions made (per coding-test requirement)

### 3.2 Out-of-Scope

| Item | Rationale |
|---|---|
| Fortify auth: login, register, password reset, 2FA, passkeys | Starter-kit boilerplate; coding test explicitly excludes |
| `settings/*` pages (`ProfileController`, `SecurityController`, form requests) | Starter-kit boilerplate |
| Real email delivery (SMTP/SES/Mailgun) | `MAIL_MAILER=log` is acceptable for the test |
| Anonymous / unauthenticated attendee registration (D-002) | User decision: authenticated-only; no name+email-only form; no token verification link |
| Double-opt-in email verification link flow (D-002) | Replaced by a simple confirmation notification email to the already-authenticated user |
| External geocoding API calls (Mapbox, Google Maps Geocoding, Nominatim) at seed or runtime (D-001) | User decision: offline nearest-CITY_ANCHOR only; no API key, no HTTP calls |
| Image upload UI (user-facing form to attach images to events) | Requirement says "add support end to end" with placeholder reuse; upload UI is not requested |
| Event creation / editing UI | Not requested |
| User-facing event management (cancel, update, delete) | Not requested |
| Multi-tenant / organizer portal | Not requested |
| Real-time features (WebSockets, Pusher, broadcasting) | Not requested |
| Mobile-specific native app | Not requested |
| Alternative Visual 2 layout (timeline, calendar, masonry) — only MAP is built (D-003) | User decision: Visual 2 = interactive map |

---

## 4. Decisions (Locked)

| ID | Decision | Rationale |
|---|---|---|
| D-001 | **Geocoding: offline nearest CITY_ANCHOR.** Map each event's lat/lng to the nearest of the ~78 seeder CITY_ANCHORS; store result in `events.location_city` (human-readable string). No external API at seed or runtime. | Deterministic, no API key, no latency, works offline |
| D-002 | **Attendee registration: authenticated via existing Fortify login.** Attendee schema = (`user_id` FK, `event_id` FK) with a unique constraint. Anonymous registration and token-based verification links are removed entirely. Confirmation email is a notification (not opt-in). | Simplest correct path; reuses existing auth; eliminates token/email-lookup complexity |
| D-003 | **Layouts: Visual 1 = responsive CARD GRID; Visual 2 = interactive MAP with pins.** Both support date + location filters and tasteful animations. | User explicit choice; card grid and map are visually and structurally distinct |
| D-004 | **Timezone: event-local timezone, derived from nearest CITY_ANCHOR via a static CITY_ANCHOR → IANA timezone map (~78 entries).** Display format: e.g. "8:00 PM CET". Date filtering operates on event-local date (the calendar date in the event's own timezone). | Avoids browser-local drift; directly linked to existing CITY_ANCHOR data; no external API |
| D-005 | **Images: 5–10 small local placeholder JPEG files committed to repo; served via Laravel `public` disk + `artisan storage:link`.** New `event_images` table (`event_id` FK, `path`, `sort_order`, `alt`). Seeder assigns 2+ images per event using BULK CHUNKED inserts matching the existing EventSeeder pattern. | Consistent with 2.5M-row scale; no image bytes in DB; no CDN dependency |

---

## 5. Domain Entities

### 5.1 Event (existing — changes needed)

**Description:** Represents a single scheduled event (concert, conference, meetup, etc.) with
all core metadata.

**Existing key attributes:**
- `id`: UUID primary key
- `user_id`: FK → users (organizer)
- `type`: event category string
- `status`: lifecycle state (draft / published / cancelled / sold_out) — indexed
- `created_time`: UNIX timestamp = event start time (NOT row created_at) — currently unindexed
- `latitude`, `longitude`: decimal coordinates — no index
- `payload`: JSON blob containing `name`, `description`, `venue`, `schedule.starts_at`, `schedule.ends_at`, `pricing`, `tags`, `notes`

**New attributes to add:**
- `location_city`: varchar — denormalized city name derived from nearest CITY_ANCHOR; indexed
- Timezone is derived at read time from the static CITY_ANCHOR → IANA timezone map using `location_city` as the lookup key; it is NOT stored as a separate column (saves migration + keeps data consistent)

**Relationships:**
- belongs to `User` (organizer) — existing
- has many `EventImage` — new
- has many `EventRegistration` (attendees) — new

### 5.2 EventImage (new)

**Description:** A locally-stored image file associated with an event. Multiple images may
belong to a single event; order is preserved.

**Key attributes:**
- `id`: auto-increment integer PK
- `event_id`: FK → events (UUID), cascade delete
- `path`: relative path within the public storage disk (e.g. `event-images/placeholder-1.jpg`)
- `sort_order`: unsigned integer — display order within the event's image set
- `alt`: varchar nullable — alternative text for accessibility
- `created_at`, `updated_at`: timestamps

**Relationships:**
- belongs to `Event`

### 5.3 EventRegistration (new — replaces "Attendee")

**Description:** A record linking an authenticated user to an event they have registered to
attend. The entity is keyed by the pair (`user_id`, `event_id`) with a unique constraint;
anonymous or email-only registration is not supported.

**Key attributes:**
- `id`: auto-increment integer PK
- `user_id`: FK → users (NOT NULL), cascade delete — the authenticated registrant
- `event_id`: FK → events (UUID, NOT NULL), cascade delete
- `status`: string — `confirmed` (default on creation)
- `reminder_3day_sent_at`: nullable timestamp — idempotency guard for 3-day reminder
- `reminder_24hour_sent_at`: nullable timestamp — idempotency guard for 24-hour reminder
- `created_at`, `updated_at`: timestamps

**Constraints:**
- Unique index on (`user_id`, `event_id`) — prevents double-registration by the same user

**Relationships:**
- belongs to `User`
- belongs to `Event`

### 5.4 CITY_ANCHOR (static reference data, not a table)

**Description:** One of ~78 hardcoded (city_name, latitude, longitude, IANA_timezone) tuples
already present in `EventSeeder`. Used at seed time to derive `location_city` for each event
(nearest-neighbor distance check), and at read time to resolve the event-local IANA timezone
for display. No database table is created; the map is a PHP array or constant.

---

## 6. User Stories

### Group A — Data Foundation (must ship before Visual pages can be built)

| ID | Title | MoSCoW | Size | Depends On |
|---|---|---|---|---|
| US-101 | Add image storage and seed placeholder images | Must | M | — |
| US-102 | Derive and store human-readable city from lat/lng (offline) | Must | M | — |
| US-103 | Index `created_time` and add `location_city` index | Must | S | US-102 |
| US-104 | Fix and extend listing query (date filter + location filter) | Must | M | US-103 |
| US-105 | Fix planted bug: `aplyFilters` typo in Index.vue | Must | S | — |
| US-106 | Build static CITY_ANCHOR → IANA timezone map | Must | S | US-102 |

### Group B — Event Visual Pages (depend on Group A)

| ID | Title | MoSCoW | Size | Depends On |
|---|---|---|---|---|
| US-201 | Event Visual 1 — responsive card grid, filters, animations | Must | L | US-101, US-102, US-104, US-106 |
| US-202 | Event Visual 2 — interactive map with pins, filters, animations | Must | L | US-101, US-102, US-104, US-106 |
| US-203 | Event detail view enhancements (images, city, event-local datetime) | Should | M | US-101, US-102, US-106 |

### Group C — Attendees & Email (depend on Group A; independent of Group B)

| ID | Title | MoSCoW | Size | Depends On |
|---|---|---|---|---|
| US-301 | Attendee registration (authenticated) and attendee list | Must | M | US-104 (event API) |
| US-302 | Confirmation notification email on registration | Must | S | US-301 |
| US-303 | Scheduler setup in `composer dev` + artisan schedule definition | Must | S | — |
| US-304 | 3-day reminder email to registered attendees | Must | M | US-301, US-303 |
| US-305 | 24-hour reminder email to registered attendees | Must | M | US-301, US-303 |

### Group D — Cross-Cutting

| ID | Title | MoSCoW | Size | Depends On |
|---|---|---|---|---|
| US-401 | Timezone-aware date/time display (event-local via CITY_ANCHOR map) | Must | S | US-106 |
| US-402 | `DECISIONS.md` — short note on implementation choices | Should | S | all |

---

## 7. Acceptance Criteria

### US-101 — Add image storage and seed placeholder images

- **AC-101-1** (rule): The system has an `event_images` table with columns `id`, `event_id`, `path`, `sort_order`, `alt`, `created_at`, `updated_at`. `event_id` is a cascading FK to `events`.
- **AC-101-2** (GWT):
  - Given: `artisan storage:link` has been run
  - When: a request is made to `GET /storage/event-images/{filename}`
  - Then: the image file is returned with an appropriate `Content-Type` header and HTTP 200
- **AC-101-3** (rule): After seeding, every event in the `events` table has at least 2 associated rows in `event_images`. Placeholder image files (5–10 JPEGs, committed to repo) are reused across events.
- **AC-101-4** (rule): No image file bytes are stored in the database. Images are files on the `public` disk; only the relative path string is stored in `event_images.path`.
- **AC-101-5** (GWT):
  - Given: an `Event` model is loaded
  - When: `$event->images` is accessed
  - Then: a collection of `EventImage` models is returned, ordered by `sort_order` ascending
- **AC-101-6** (rule): The image seeder uses bulk chunked inserts consistent with the existing EventSeeder pattern; it does not loop one-row-at-a-time over 1.25M events.

### US-102 — Derive and store human-readable city from lat/lng (offline)

- **AC-102-1** (rule): The `events` table has a `location_city` varchar column (nullable, indexed).
- **AC-102-2** (GWT):
  - Given: an event row with a `latitude`/`longitude` coordinate
  - When: the city-derivation logic runs
  - Then: `location_city` is set to the name of the nearest CITY_ANCHOR (e.g. "New York")
- **AC-102-3** (rule): The derivation makes no external HTTP requests. It uses only the in-memory CITY_ANCHORS list.
- **AC-102-4** (rule): A migration or artisan command populates `location_city` for all existing rows using a single bulk UPDATE pass; no per-row PHP loop over 1.25M rows.

### US-103 — Index `created_time` and `location_city`

- **AC-103-1** (rule): A migration adds a single-column B-tree index on `events.created_time`.
- **AC-103-2** (rule): A migration adds a single-column B-tree index on `events.location_city`.
- **AC-103-3** (GWT):
  - Given: the 1.25M-row dataset
  - When: `GET /events/data?status=published` is requested
  - Then: the median response time is under 500 ms (measured by the existing `stats.ms` harness)

### US-104 — Fix and extend listing query

- **AC-104-1** (GWT):
  - Given: a request with `?from=2026-01-01`
  - When: `GET /events/data` is called
  - Then: the response contains only events where the event-local calendar date is ≥ 2026-01-01
- **AC-104-2** (GWT):
  - Given: a request with `?to=2026-12-31`
  - When: `GET /events/data` is called
  - Then: the response contains only events where the event-local calendar date is ≤ 2026-12-31
- **AC-104-3** (GWT):
  - Given: a request with `?city=London`
  - When: `GET /events/data` is called
  - Then: the response contains only events where `location_city = 'London'`
- **AC-104-4** (rule): The listing query selects only the columns required for display (id, type, status, created_time, location_city, latitude, longitude) plus image path(s); it does NOT SELECT the full `payload` column.
- **AC-104-5** (rule): Pagination remains at ≤ 50 events per page with cursor, keyset, or indexed-offset approach that does not degrade beyond page 10.

### US-105 — Fix planted bug

- **AC-105-1** (GWT):
  - Given: the user selects a status filter in `Events/Index.vue`
  - When: the user clicks the "Filter" button
  - Then: the event list refreshes with the selected filter applied (the handler is called without a JS error)

### US-106 — Build static CITY_ANCHOR → IANA timezone map

- **AC-106-1** (rule): A PHP constant or array maps each of the ~78 CITY_ANCHOR city names to a valid IANA timezone identifier (e.g. `"New York" => "America/New_York"`).
- **AC-106-2** (rule): Given any `location_city` value produced by US-102, the map returns a non-null IANA timezone string.
- **AC-106-3** (rule): The map is defined in one canonical location in the codebase; it is not duplicated across multiple files.

### US-201 — Event Visual 1 (Card Grid)

- **AC-201-1** (GWT):
  - Given: the user navigates to `/events-visual-1`
  - When: the page loads
  - Then: a responsive card grid of events is displayed, each card showing title, description excerpt, city, event-local date/time with TZ label, and at least one image; all styled with Tailwind CSS
- **AC-201-2** (GWT):
  - Given: the user enters a date range in the filter controls on Visual 1
  - When: the filter is applied
  - Then: only events whose event-local date falls within that range are shown, without a full page reload
- **AC-201-3** (GWT):
  - Given: the user selects a city in the filter controls on Visual 1
  - When: the filter is applied
  - Then: only events matching that city are shown
- **AC-201-4** (rule): The card grid layout is visually and structurally distinct from the map layout used in Visual 2.
- **AC-201-5** (rule): At least one CSS/JS transition or animation is present on the card grid (e.g. card appear, filter transition) and plays without user-perceived jank. Animations are not applied to every element.
- **AC-201-6** (rule): The page passes WCAG 2.1 AA contrast requirements for all text elements.

### US-202 — Event Visual 2 (Interactive Map)

- **AC-202-1** (GWT):
  - Given: the user navigates to `/events-visual-2`
  - When: the page loads
  - Then: an interactive map is displayed with a pin for each event positioned at its lat/lng; clicking a pin shows at minimum the event title, city, and event-local date/time with TZ label
- **AC-202-2** (GWT):
  - Given: the user enters a date range in the filter controls on Visual 2
  - When: the filter is applied
  - Then: only event pins whose event-local date falls within that range remain visible on the map
- **AC-202-3** (GWT):
  - Given: the user selects a city in the filter controls on Visual 2
  - When: the filter is applied
  - Then: only pins for events in that city remain visible
- **AC-202-4** (rule): The map layout is structurally and visually distinct from the card grid of Visual 1 (different paradigm — spatial vs. list/grid).
- **AC-202-5** (rule): At least one tasteful animation is present (e.g. pin drop, popup fade) without user-perceived jank.
- **AC-202-6** (rule): The page passes WCAG 2.1 AA contrast requirements for all text elements.

### US-203 — Event detail view enhancements

- **AC-203-1** (GWT):
  - Given: an event with images
  - When: the user views the event detail page
  - Then: all associated images are displayed (gallery or carousel); images are served from local storage
- **AC-203-2** (GWT):
  - Given: an event with `location_city` populated
  - When: the user views the event detail page
  - Then: the human-readable city name is displayed (not raw lat/lng coordinates)
- **AC-203-3** (rule): The event start and end date/time are displayed in event-local timezone format consistent with US-401 (e.g. "8:00 PM CET").

### US-301 — Attendee registration (authenticated) and attendee list

- **AC-301-1** (GWT):
  - Given: the user is authenticated and is viewing an event page
  - When: they submit the registration form
  - Then: an `EventRegistration` record is created with `status = confirmed` and a success message is shown
- **AC-301-2** (rule): Attempting to register the same authenticated user for the same event a second time returns a validation error; no duplicate `EventRegistration` row is created.
- **AC-301-3** (GWT):
  - Given: an event has registered attendees
  - When: the attendee list section is viewed
  - Then: the list shows each attendee's display name and registration date; no email address or user ID is exposed publicly
- **AC-301-4** (rule): The `event_registrations` table enforces a unique constraint on (`user_id`, `event_id`).
- **AC-301-5** (rule): An unauthenticated visitor who attempts to register is redirected to the login page (standard Fortify behavior); no anonymous registration path exists.

### US-302 — Confirmation notification email

- **AC-302-1** (GWT):
  - Given: an `EventRegistration` is successfully created
  - When: the registration is saved
  - Then: a confirmation email job is dispatched to the queue within the same request
- **AC-302-2** (rule): The confirmation email is a notification (not a double-opt-in verification link); it contains the event title, event-local date/time with TZ label, and event city.
- **AC-302-3** (rule): In the test environment (`MAIL_MAILER=array`), asserting `Mail::assertSent(AttendeeConfirmationMail::class)` passes after a registration.

### US-303 — Scheduler setup

- **AC-303-1** (rule): Running `composer dev` starts a `schedule:work` process alongside the existing `serve`, `queue:listen`, `pail`, and `vite` processes.
- **AC-303-2** (rule): `php artisan schedule:list` shows the reminder command scheduled to run at least once per hour.

### US-304 — 3-day reminder email

- **AC-304-1** (GWT):
  - Given: an `EventRegistration` exists for an event whose start time (in event-local timezone) is between 71 and 73 hours from now
  - When: the reminder command runs
  - Then: a 3-day reminder email job is dispatched to the queue for that registrant
- **AC-304-2** (rule): If `reminder_3day_sent_at` is not null for a given registration, the command does NOT dispatch a second job.
- **AC-304-3** (rule): The 3-day reminder email contains the event title, event-local start date/time with TZ label, and city.

### US-305 — 24-hour reminder email

- **AC-305-1** (GWT):
  - Given: an `EventRegistration` exists for an event whose start time (in event-local timezone) is between 23 and 25 hours from now
  - When: the reminder command runs
  - Then: a 24-hour reminder email job is dispatched to the queue for that registrant
- **AC-305-2** (rule): If `reminder_24hour_sent_at` is not null for a given registration, the command does NOT dispatch a second job.
- **AC-305-3** (rule): Events whose `created_time` (start time) is in the past are never targeted by reminder dispatch.

### US-401 — Timezone-aware date/time display (event-local via CITY_ANCHOR map)

- **AC-401-1** (rule): All event date/times are stored and queried as UTC (UNIX timestamps on the backend; ISO 8601 UTC in API responses).
- **AC-401-2** (rule): All displayed event date/times are converted to the event-local IANA timezone (resolved via the CITY_ANCHOR → IANA timezone map from US-106) and shown with a TZ abbreviation label (e.g. "8:00 PM CET").
- **AC-401-3** (rule): Date filtering (`from` / `to`) operates on event-local dates, not UTC dates. This behavior is documented in `DECISIONS.md`.
- **AC-401-4** (rule): A date/time library (`date-fns`, `luxon`, or `dayjs`) is added to `package.json` for frontend timezone formatting; no manual string parsing of timestamps in Vue components.

---

## 8. Non-Functional Requirements

| ID | Category | Requirement | Priority | Verification |
|---|---|---|---|---|
| NFR-001 | Performance | Listing API (`/events/data`) P95 response time ≤ 500 ms on the 1.25M-row dataset with status + date + city filters applied | Must | Measured via existing `stats.ms` harness in the response; manual smoke test |
| NFR-002 | Performance | The listing query must NOT select the `payload` column in list views; only specific scalar columns | Must | Code review: confirm no `SELECT *` or `->get(['payload'])` in listing queries |
| NFR-003 | Performance | Reminder command query must use indexed columns only; must not full-scan `event_registrations` or `events` | Must | EXPLAIN QUERY PLAN confirms index use |
| NFR-004 | Scalability | Image seeding must not meaningfully grow the SQLite file — placeholder files are reused; only file path strings are stored in DB | Must | DB file size increase < 10 MB after image seeding |
| NFR-005 | Reliability | Reminder emails are idempotent: each registrant receives exactly one 3-day email and one 24-hour email per event | Must | Pest test: run command twice in same window; assert job dispatched once |
| NFR-006 | Reliability | Confirmation email is queued (not sent inline); the queue worker processes it without failure | Must | Pest test with `Queue::fake()` + `Queue::assertPushed(AttendeeConfirmationJob::class)` |
| NFR-007 | Security | Attendee email addresses and user IDs are never exposed in any public-facing API response or frontend component | Must | Code review + Pest test asserting email/user_id absent from attendee list response |
| NFR-008 | Usability | Both Visual pages display a meaningful empty state when no events match the active filters | Should | Manual test: apply filters that match zero events; confirm message is shown |
| NFR-009 | Usability | Filter controls are accessible via keyboard (focusable, labeled); both Visual pages pass WCAG 2.1 AA color contrast | Should | axe DevTools or Lighthouse accessibility audit |
| NFR-010 | Maintainability | `composer test` (pint + phpstan level 7 + pest) passes with zero errors after all changes | Must | CI run + local `composer test` output |
| NFR-011 | Maintainability | ESLint + Prettier pass (`npm run lint`, `npm run format`) on all new/modified Vue/TS files | Must | CI lint workflow passes |
| NFR-012 | Maintainability | New Pest tests cover: image serving, city derivation, date filter, location filter, authenticated attendee registration duplicate guard, confirmation email dispatch, reminder dispatch + idempotency | Must | `composer test` output shows all new tests pass |

---

## 9. Impact Analysis

### Affected Features

| Feature | Impact | Risk Level |
|---|---|---|
| `EventController::loadListing()` | Extended with date (event-local basis) + location filters; column selection narrowed | Medium |
| `Events/Index.vue` | Bug fix (typo); filter logic corrected | Low |
| `Event` model | New `images()` and `registrations()` relations; `location_city` attribute | Low |
| `routes/console.php` / Console Kernel | New scheduled command added | Low |
| `composer.json` dev script | New `schedule:work` process added | Low |

### Affected Components

| Component | Nature of Change |
|---|---|
| Database migrations | 3 new migrations: `event_images`, `event_registrations`, add `location_city` + indexes to `events` |
| `app/Models/` | `EventImage` (new), `EventRegistration` (new), `Event` (extended) |
| `app/Http/Controllers/` | `EventController` (extended), new `EventRegistrationController` |
| `app/Mail/` or `app/Notifications/` | `AttendeeConfirmationMail` (new), `EventReminderMail` (new) |
| `app/Console/Commands/` | `SendEventReminders` (new) |
| `app/Support/` or `app/Services/` | City-anchor nearest-neighbor helper + IANA timezone map constant |
| `resources/js/pages/Events/` | `VisualOne.vue` (card grid), `VisualTwo.vue` (interactive map), `Show.vue` (enhanced) |
| `resources/js/types/` | New TypeScript types for `EventImage`, `EventRegistration`, extended `Event` |
| `routes/web.php` | New attendee registration route |
| `database/seeders/` | `EventImageSeeder` or extension to `EventSeeder`; bulk chunked inserts |
| `package.json` | New date/time library; map library for Visual 2 (e.g. Leaflet or MapLibre) |
| `public/event-images/` (or `storage/app/public/event-images/`) | 5–10 placeholder JPEG files committed to repo |

### Breaking Change Risk: Low

`events.visual1` and `events.visual2` routes must continue returning HTTP 200 (existing test
`tests/Feature/EventListingTest.php` checks these). The `VisualOne.vue` and `VisualTwo.vue`
components are currently empty stubs — replacing their content is the entire goal and carries no
breaking-change risk as long as the route still resolves. Renaming the `attendees` table to
`event_registrations` is a schema choice that has no existing references.

---

## 10. Dependencies & Sequencing (Wave Plan)

```
Wave 0 (Foundation — blocks everything):
  US-106  Build CITY_ANCHOR → IANA timezone map              [PHP constant / array]
  US-103  Add DB indexes (created_time, location_city)       [migrations only]
  US-102  Derive + store location_city (offline)             [migration + command]
  US-101  EventImage table + placeholder files + seeding     [migration + seeder]
  US-105  Fix aplyFilters typo                               [1-line frontend fix]
  US-401  Timezone strategy: install date-fns/luxon          [config + package]

Wave 1 (Backend query layer — blocks Visual pages):
  US-104  Fix + extend loadListing (event-local date filter, city filter, column selection)

Wave 2 (Frontend Visual pages — parallel):
  US-201  Event Visual 1 (card grid)
  US-202  Event Visual 2 (interactive map with pins)
  US-203  Event detail enhancements (images, city, event-local datetime)

Wave 3 (Attendees — parallel with Wave 2):
  US-301  EventRegistration schema + authenticated registration endpoint + list
  US-302  Confirmation notification email

Wave 4 (Scheduler + Reminders — depends on Wave 3):
  US-303  Scheduler setup
  US-304  3-day reminder
  US-305  24-hour reminder

Wave 5 (Wrap-up):
  US-402  DECISIONS.md
```

---

## 11. Assumptions

| ID | Assumption | Consequence if Wrong |
|---|---|---|
| A-001 | The nearest-CITY_ANCHOR approach is acceptable as "human-readable address" for the coding test — no external geocoder is required | If the evaluator expects a full street address, an external API (Nominatim, Mapbox) would be needed, adding latency and an API key requirement |
| A-002 | The 5–10 placeholder JPEG files will each be small (≤ 100 KB); total repo size increase < 1 MB | If the evaluator checks image diversity or authenticity (i.e. expects unique images per event), placeholder reuse will fail that expectation |
| A-003 | The reminder command runs on a per-hour schedule. The idempotency window for "3 days before" is `event_start` between `now + 71h` and `now + 73h`; "24 hours before" is `now + 23h` to `now + 25h` | If the scheduler runs less frequently than hourly, some events may fall outside the window and miss a reminder |
| A-004 | `MAIL_MAILER=log` is the accepted mailer for local development. The evaluator will verify email behaviour via log output or test assertions (`Mail::fake()`), not by checking an inbox | If the evaluator actually expects to receive test emails, a real SMTP mailer configuration would be needed |
| A-005 | Payload type inconsistency (seeder uses strings, factory uses numbers) will be handled by a PHP transformer/resource layer that normalizes all payload fields to canonical types before sending to the frontend | If normalization is skipped, Vue components may display "NaN" or break on type coercion |
| A-006 | The `composer dev` script change (adding `schedule:work`) is acceptable for this project. No separate cron/supervisor configuration is needed for the test | In a real production deployment, `schedule:work` would run as a separate managed process |
| A-007 | Each of the ~78 CITY_ANchors maps to a unique IANA timezone; no two city anchors share the same city name with different timezones | If a name collision exists, the map lookup must be keyed on (city_name, country) rather than city_name alone |
| A-008 | The map library used for Visual 2 (e.g. Leaflet, MapLibre GL) is compatible with Vue 3 + Inertia.js and can be added via `npm` without conflicting with existing dependencies | If a library conflict arises, an alternative map library must be chosen |

---

## 12. Glossary

| Term | Definition |
|---|---|
| `created_time` | UNIX timestamp column on `events`; represents the event START time, not the row's creation time |
| CITY_ANCHOR | One of ~78 real-world (city_name, latitude, longitude, IANA_timezone) tuples hardcoded in `EventSeeder`; used to cluster seeded event coordinates and to derive `location_city` and event-local timezone |
| `location_city` | Denormalized varchar column on `events`; stores the name of the nearest CITY_ANCHOR to the event's coordinates |
| Event-local timezone | The IANA timezone derived by looking up `location_city` in the static CITY_ANCHOR → IANA timezone map; used for display and date filtering |
| `payload` | `longText` (JSON) column on `events`; holds the bulk of event metadata (name, description, venue, schedule, pricing, tags, notes) |
| Planted bug | Deliberate typo at `Events/Index.vue:148` — `aplyFilters` instead of `applyFilters`; the Filter button is a no-op |
| Idempotent reminder | A reminder email dispatch guaranteed to fire exactly once per registrant per threshold (3-day / 24-hour), enforced by the `reminder_3day_sent_at` / `reminder_24hour_sent_at` timestamp columns |
| `public` disk | Laravel filesystem disk rooted at `storage/app/public`, symlinked to `public/storage` via `artisan storage:link`; used for locally-served event images |
| `loadListing` | Private method in `EventController` that builds and runs the events listing query; currently filters only by `status` |
| `EventRegistration` | New table/model representing an authenticated user's registration for an event; keyed on (`user_id`, `event_id`) unique pair — replaces the earlier draft's "Attendee" concept |
| Confirmation notification | Email sent immediately on successful registration; informs the user they are registered; does NOT contain a verification/opt-in link |
