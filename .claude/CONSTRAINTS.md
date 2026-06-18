# Active Constraints & Risks

_Last updated: 2026-06-18_
_Updated after: TASK-3 (Wave 0) completed_

---

## Resolved Constraints (TASK-3, 2026-06-18)

| Constraint | Resolution | ADR | Status |
|---|---|---|---|
| Address/location derivation strategy | `ReverseGeocoder` interface w/ `DatabaseReverseGeocoder` (bbox + 24h cache); `cities` table seeded from CityAnchor (~78 entries); indexed on lat/lng for fast lookup | ADR-010/011/012 | ✓ TASK-3 |
| Timezone display strategy | `TimezoneService::formatEventTime()` returns event-local time + IANA tz_identifier; City→IANA mapping static, stored in cities table | ADR-004 | ✓ TASK-3 |
| Image storage | `event_images` table (event_id, image_path, order); 8 placeholder JPEGs in repo; served via `storage/app/public` → `/storage/...` URLs | ADR-005 | ✓ TASK-3 |
| Event location denormalization | `events.location_city` column (indexed); backfilled via `events:geocode-cities` ETL command | ADR-010/011/012 | ✓ TASK-3 |
| Planted bug fix | `Events/Index.vue:148` typo fixed: `aplyFilters` → `applyFilters` | — | ✓ TASK-3 |
| PHPStan L7 compliance | All pre-existing PHPStan errors fixed; generics on relations, env() only in config | — | ✓ TASK-3 |

---

## Active Constraints (Open — to be addressed in future waves)

---

## Scale & Performance (Still Open — Wave 1)

### 1.25M events, ~2.5 GB SQLite, performance requirements
- **Detail**: Default dataset is `SEED_ROWS=1_250_000` events. Listing query must be fast; filtering by date + location requires careful indexing.
- **Impact**: Must add `created_time` index and `location_city` index during Wave 1; denormalize frequently filtered fields.
- **Action required**: Create migration for indexes + bulk population of `location_city` in Wave 1 (TASK-3 planning phase).

### No scheduler yet; queue worker only
- **Detail**: `composer dev` runs `queue:listen` (worker), but there is no `schedule:work` (scheduler).
- **Impact**: Reminder emails cannot be dispatched without a scheduler process.
- **Action required**: Add `schedule:work` to `composer dev` dev commands (separate process). Create `SendEventReminders` command that runs at fixed interval, scans for events approaching thresholds, dispatches jobs idempotently.

---

## Data Model Quirks (Reference — already catalogued in Wave 1)

### CityAnchor PHP class: seed-data source only (ADR-010/011/012 — Revision R1)
- **Detail**: Historical `CityAnchor` class contained ~78 hardcoded city anchors (name, lat, lng, timezone). Previously loaded into memory at runtime; now (R1) demoted to seed-data source only.
- **Impact**: Reference data is now in a queryable `cities` DB table (indexed on lat/lng), not in-memory PHP class. Timezone mapping also stored in DB rather than static map.
- **Reference**: Full R1 design detail in tasks/3-data-foundation/ARCHITECTURE.md (ADR-010/011/012 sections).

### `created_time` is event START time, not creation timestamp
- **Detail**: Column `created_time` holds a UNIX timestamp that IS the event start time (not `created_at`).
  Also duplicated in `payload.schedule.starts_at`.
- **Impact**: All date/time queries, filtering, and display logic must treat `created_time` as the event date, not row creation time.
- **Mitigation**: Document in code comments; use clear variable names (`eventStartTs`, not `createdTime`); add a database comment on the column.

### Most event data lives in JSON `payload` column
- **Detail**: Fields like `name`, `description`, `venue`, `schedule`, `pricing`, `tags`, `notes` are inside `payload` (longText),
  not normalized columns.
- **Impact**: Querying/filtering by these fields requires JSON extraction (slow on SQLite at scale) or denormalization.
  Frontend must decode `JSON.parse(event.payload)` when needed.
- **Mitigation**: Add indexed columns for frequently filtered/sorted fields (`name` from payload, or denormalized `location_city`);
  avoid `SELECT payload` in list queries; cache decoded values in Vue components.

### Payload type inconsistency
- **Detail**: Seeder stores some payload fields as **strings** (via strtr template substitution);
  factory stores them as native **numbers** (ints/floats).
- **Impact**: Frontend code cannot assume `payload.venue.capacity` is numeric; must coerce.
- **Mitigation**: Add a PHP resource/transformer to normalize payload on serialization; or document the types clearly.

---

## Scale & Performance

### 1.25M events, ~2.5 GB SQLite, unindexed `created_time`
- **Detail**: Default dataset is `SEED_ROWS=1_250_000` events (configurable via env).
  Listing query does `orderByDesc('created_time')` with **no index**, causing expensive sorts on every page load.
- **Impact**: First page load is slow (~3s comment); pagination degrades with scale.
- **Mitigation**: Add `created_time` index immediately. Any new filter column must be indexed (e.g. `location_city`).
  Use `SELECT` specific columns, avoid eagerly loading `payload` in listings.

### No image column; need 2+ images per event
- **Detail**: Events have zero image storage; test requires ≥2 images/event served locally.
- **Impact**: Must add schema (new table or denormalized column) + seeding logic + storage endpoint + frontend display.
- **Mitigation**: Create `event_images` table (`event_id`, `image_path`, `order`); seed with placeholder reuse (store a few
  placeholder files, assign randomly to events to avoid bloating the 2.5 GB DB further).
  Serve from `storage/app/public` (symlinked at `public/storage`).

### No address/location; only lat/lng
- **Detail**: Events carry `latitude`, `longitude` but no human-readable location string.
- **Impact**: UI must show meaningless coords or reverse-geocode on-demand (slow at 1.25M scale).
- **Mitigation**: Precompute city for each event. Seeder clusters around ~78 real city anchors; find nearest anchor per event,
  store the city name in a denormalized `location_city` column (indexed). No external API calls needed.
  Offline, deterministic, fits the data distribution.

---

## Feature Gaps

### Filter by date + location not backend-implemented
- **Detail**: `EventController::loadListing()` only filters by `status`; `from` date param is in props but ignored;
  no location filter exists.
- **Impact**: Requirement #6 (filter by date + location) cannot be met without backend changes.
- **Mitigation**: Add `from` (and optional `to`) date filtering; add location filter (e.g. by city or radius).
  Fix the typo bug (`aplyFilters` → `applyFilters`); reset pagination when filters change.

### Attendees schema missing
- **Detail**: No `attendees` or `event_user` table, no relation on Event model.
- **Impact**: Cannot register interest/attendance.
- **Mitigation**: Add `attendees` table (PK: `id`, FK: `event_id`, FK: `user_id` or `email`, status: registered/interested).
  Add `Event::attendees()` relation.

### No scheduler; queue worker only
- **Detail**: `composer dev` runs `queue:listen` (worker), but there is no `schedule:work` (scheduler).
  Laravel scheduled commands require the scheduler to dispatch them.
- **Impact**: Reminder emails cannot be scheduled (e.g. send at 3 days before event).
- **Mitigation**: Add `schedule:work` to `composer dev` dev commands (separate process). Or use a standalone cron job in production.
  Create a command (e.g. `SendEventReminders`) that runs at a fixed interval (every hour), scans for events approaching 3-day / 24-hour
  thresholds, and dispatches jobs to attendees. Ensure idempotency (each attendee gets exactly one email per threshold).

### `MAIL_MAILER=log` in local
- **Detail**: Confirmation + reminder emails are logged, not actually sent.
- **Impact**: Testing emails requires reading logs or switching to `array` driver in tests.
- **Mitigation**: This is acceptable for local dev. In tests, use `Mail::fake()` or set `MAIL_MAILER=array` to assert on email count/content.
  Production will use a real mailer (SES, Mailgun, etc.).

---

## Auth & Access Control

### Events routes are public; auth model for attendee registration TBD
- **Detail**: `/events*` and the visual pages are unauthenticated. Fortify auth is present and working.
- **Impact**: Attendee registration can be public, authenticated, or anonymous-with-email.
- **Mitigation**: Decide early: require login, allow email capture without auth, or token-based confirmation.
  Document in the registration form + confirmation flow.

---

## Testing

### No tests for images, attendees, geocoding, or reminders yet
- **Detail**: Current test suite covers auth + basic event listing.
  New features (images, attendees, geocoding, reminders) have zero test coverage.
- **Impact**: Implementation must include tests for every new feature (especially reminder scheduling + email dispatch).
- **Mitigation**: Use `EventFactory` for test events; create factories for `EventImage`, `Attendee`.
  Mock external geocoding (if used). Test reminder job dispatch + email mailable.
  Keep tests in-memory (no seeding real 1.25M rows in tests).

---

## Known Bugs

### `Events/Index.vue:148` Filter button typo: `aplyFilters` (should be `applyFilters`)
- **Status**: Identified
- **Mitigation**: Fix the typo or replace this debug UI with the new visual pages entirely.

---

## Dependencies Not Yet Installed

The following will be needed for the coding test and are **not in `package.json` yet**:

- **Date/time library**: `date-fns`, `luxon`, or `dayjs` (for timezone handling, formatting)
- **Map library** (if map visual): `leaflet`, `mapbox-gl`, or `google-maps` API
- **Image upload** (if user can add images): `dropzone`, `filepond`, or similar
- **Reverse geocoding** (if external): service API key + client library (or precompute offline, recommended)

Candidate will add these as needed during implementation.

---

## Seeding & Dataset Characteristics

- **Default seed**: `SEED_ROWS=1_250_000` events, `NUM_USERS=3000`, events cluster around ~78 real city anchors.
- **Payload average size**: ~1.5 KB per event (includes Lorem-ipsum padding).
- **Temporal spread**: `created_time` (event start) randomly spans ±1 year from now (roughly 50% future, 50% past).
- **Insertion strategy**: chunked bulk inserts (4000 rows/txn) with SQLite pragmas for speed (journal=MEMORY, sync=OFF during seeding).
- **Tests**: in-memory SQLite, no seed data, fast (all tests run synchronously).

**Do not seed test DB with 1.25M rows** — test DB is `:memory:` for speed. Use factories for individual test events.
