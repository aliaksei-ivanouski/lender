# Completed Tasks — Append-Only Log

_Last updated: 2026-06-18_

---

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
