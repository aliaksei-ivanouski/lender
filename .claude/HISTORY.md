# Completed Tasks — Append-Only Log

_Last updated: 2026-06-18_

---

## Task 6: Attendees & Confirmation Email (Wave 3)
- **Branch**: `feat/us-006` (merged)
- **Completed**: 2026-06-18
- **Status**: MERGED (PR #10 + follow-up PR #11)
- **Deliverables**:
  - `event_registrations` table (user_id FK, event_id FK, unique constraint) + status + reminder-sent tracking columns reserved for Wave 4
  - `EventRegistration` model + `Event::registrations()` + `Event::attendeesCount`; User relations: registrations/registeredEvents
  - Auth-gated POST/DELETE `/events/{event}/registrations` (EventRegistrationController) — firstOrCreate dedup, notify only on wasRecentlyCreated, redirect-back + flash messaging
  - Guests redirected to Fortify login
  - `RegistrationConfirmationNotification` queued (mail channel) on new registration
  - `Events/Show.vue` (US-203) — hero/gallery, event-local date/time, venue+address, description, Register/Unregister button or "Log in to register"
  - Attendee count + first 20 names (names only — no PII) displayed
  - `EventController@show` returns enriched event + attendees + attendeesCount + isRegistered + isAuthenticated
  - Mail logging: `MAIL_MAILER=array` in tests; MessageSent listener logs concise one-liner (no full HTML dump)
  - Test baseline: 86 passing (Pest, in-memory SQLite)
  - Archive: `tasks/6-attendees/`

## Task 5: Event Visual Pages (Wave 2)
- **Branch**: `feat/us-005` (merged)
- **Completed**: 2026-06-18
- **Status**: MERGED (PR #9)
- **Deliverables**:
  - Visual 1: responsive card grid (EventCard + EventsSkeleton + EventsEmpty components) with infinite pagination
  - Visual 2: Leaflet + OpenStreetMap interactive map with Leaflet.MarkerCluster, viewport-synced event list, 2000-event cap
  - Shared `useEventsData` composable (fetch, filter, paginate, loadAll)
  - `resources/js/components/ui/date-picker` — en-US (MM/DD/YYYY), teleported calendar, min/max bounds, close-on-select, keyboard-only focus rings
  - `lib/format.ts` — formatStatus humanizer
  - Routing: `events-visual-1` + `events-visual-2` → EventController `visualOne()` + `visualTwo()` with sharedListingProps
  - Removed: `Events/Index.vue` table page (deleted); home route → `/events-visual-1`
  - One-command setup: `bin/setup` (composer install + key + migrate + seed + npm build)
  - Idempotent seeders (safe re-run)
  - Placeholder images: 8 JPEGs in `database/seeders/images/` + `public/storage` symlink via `artisan storage:link`
  - ADR-013: Leaflet + OSM selected for map (dynamic import, no bundle bloat)
  - Test baseline: 74 passing (Pest, in-memory SQLite)
  - Archive: `tasks/5-visual-pages/`

## Task 4: Events Listing Query & Filters (Wave 1)
- **Branch**: `feat/us-004` (merged)
- **Completed**: 2026-06-18
- **Status**: MERGED (PR #8)
- **Deliverables**:
  - EventController `list()` endpoint — query date/location filters, pagination, enriched EventResource
  - Events/FilterBar.vue — date range picker, location dropdown, apply/clear buttons
  - JSON payload extraction for frontend rendering (name, description, venue, schedule tags)
  - Test coverage: filtering by created_time range, city name
  - Archive: `tasks/4-events-listing-query/`

## Task 3: Data Foundation (Wave 0)
- **Branch**: `feat/us-003` (merged)
- **Completed**: 2026-06-18
- **Status**: MERGED (PR #7)
- **Deliverables**:
  - `cities` table (name, region, country, lat, lng, timezone) seeded from CityAnchor
  - `ReverseGeocoder` port/adapter with `DatabaseReverseGeocoder` implementation (bbox + 24h cache)
  - `event_images` table + `EventImage` model + bulk seeding with 8 placeholder JPEGs
  - `TimezoneService` for event-local time formatting (formatEventTime returns starts_at_local, starts_at_date, tz_label, tz_identifier, utc_timestamp)
  - `events.location_city` column (indexed) + migration for bulk population
  - `events.created_time` index + location_city index
  - ETL backfill job: `events:geocode-cities` (chunkById + grouped bulk UPDATE)
  - Planted bug fix: `Events/Index.vue:148` typo `aplyFilters` → `applyFilters`
  - PHPStan L7 fixes (all pre-existing errors resolved)
  - Test baseline: 62 passing (Pest, in-memory SQLite)
  - Archive: `tasks/3-data-foundation/`

## Task 2: Scope & User Stories (Business Analysis)
- **Branch**: `feat/us-002` (in_review)
- **Completed**: 2026-06-18
- **Status**: FINAL BRS, PR pending merge
- **Deliverables**:
  - `tasks/2-scope-and-user-stories/BUSINESS_ANALYSIS.md` — authoritative backlog
  - 18 user stories (US-101..106, US-201..203, US-301..305, US-401..402)
  - 5 locked ADRs (ADR-001 through ADR-005; see ARCHITECTURE.md)
  - 6-wave implementation plan

## Task 1: Codebase Research & Project State Init
- **Branch**: `feat/us-001`
- **Completed**: 2026-06-18
- **Status**: MERGED (PR #2)
- **Deliverables**:
  - Full codebase audit → `tasks/1-codebase-research/RESEARCH.md` (on main)
  - Project state files created (on main: `.claude/project-state.md`, `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PATTERNS.md`, `HISTORY.md`)
  - 11 gaps identified and catalogued
  - Archive directory: `tasks/1-codebase-research/`
