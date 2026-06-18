# Project State — Index (Tier 1)
_Last updated: 2026-06-18_
_Updated after: TASK-5 (Wave 2 visual pages) merged + TASK-4 (Wave 1 events listing) merged_

**Events Visuals** — Laravel 13 + Vue 3 Inertia starter-kit with large seeded dataset (1.25M events, 3000 users).
Build target: `Events/VisualOne.vue` + `Events/VisualTwo.vue` (empty stubs) + image support + filtering + attendee registration + reminder emails.

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
| **Test** | `composer test` (config:clear + pint + phpstan + artisan test) | 74 passing (Pest, in-memory SQLite) |
| **Lint** | `composer lint` (pint) | — |
| **Types** | `composer types:check` (phpstan L7) + `npm run types:check` (vue-tsc) | — |
| **Build** | `npm run build` (Vite + Tailwind) | — |

---

## Active Tasks

*None in progress. Wave 3 (attendees & emails) will begin when next scheduled.*

---

## Active Constraints Summary

- **`created_time` is UNIX timestamp = event START time, not `created_at`** — queryable as event date; also in `payload.schedule.starts_at`
- **Most event data in JSON `payload` column** (name, description, venue, schedule, pricing, tags) — queries require JSON extraction or denormalization
- **No images yet** — add storage (table + seeding) for ≥2 images/event, served from `storage/app/public`
- **Lat/lng only, no addresses** — reverse-geocode or use nearest city anchor (seeder clusters ~78 real city anchors); precompute, do not geocode per-request at 1.25M scale
- **Only DB index on `status`** — `created_time` unindexed; filter by date + location requires new indexes + denormalization
- **Planted bug**: `Events/Index.vue:148` `@click="aplyFilters"` (typo; function is `applyFilters`); Filter button is no-op
- **Date + location filters not backend-implemented** — `from` plumbed but `loadListing` ignores it; no location filter at all
- **No attendees schema yet** — add `attendees`/`event_user` table + relation
- **No scheduler** — queue worker runs (`queue:listen`), but no `schedule:work` for reminder emails
- **`MAIL_MAILER=log`** — emails logged not sent; test via `storage/logs` or `pail`; acceptable for local dev
- **Auth not enforced on event routes** — `/events*` and visual pages are public; decide attendee registration auth model
- **Payload type inconsistency** — seeder stores strings; factory stores numbers; don't assume numeric types

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
