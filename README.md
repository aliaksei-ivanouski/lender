# LHP Coding Test — Event Visuals

A Laravel + Vue/Inertia application seeded with a large, realistic events dataset. This repository
is a take-home coding test; the full requirements are in [CODING_TEST.md](./CODING_TEST.md).

The task is to build two distinct event-browsing pages (Event Visuals 1 & 2), add image and
address support, implement date/location filtering, and wire up attendee registration with
confirmation and reminder emails — all on top of the existing Laravel 13 starter-kit scaffolding.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend runtime | PHP 8.3+ |
| Framework | Laravel 13.7 |
| Auth | Laravel Fortify 1.37 (login, registration, 2FA, passkeys) |
| Server-side SPA bridge | Inertia.js 3 (Laravel adapter) |
| Frontend framework | Vue 3.5 + TypeScript 5.2 |
| Styling | Tailwind CSS v4 |
| Build tool | Vite 8 |
| UI primitives | reka-ui 2.9 (shadcn-vue style, ~40 components) |
| Database | SQLite (file, dev) / SQLite `:memory:` (tests) |
| Queue | Database queue (`jobs` table, Laravel queue worker) |
| Mail | Log driver in dev (emails written to `storage/logs`) |
| Test runner | Pest 4.7 + PHPStan level 7 + Pint |
| Typed route helpers | Laravel Wayfinder |
| Docker dev env | Laravel Sail (dev dependency) |

---

## Prerequisites

| Requirement | Version |
|---|---|
| PHP | 8.3 or higher (`^8.3`) |
| Composer | 2.x |
| Node.js | 20+ recommended (Vite 8 requirement); 22 is fine |
| npm | Comes with Node; npm is used by CI and `composer setup` |
| SQLite | Bundled with PHP on most systems; verify with `php -m \| grep sqlite` |

> **Note on `.npmrc`:** the repo includes an `.npmrc` file with `ignore-scripts=true`.
> This is a security default for some setups. `composer setup` runs `npm install` directly so
> this should not matter for local use. If you see issues with optional native binaries, you can
> temporarily override with `npm install --ignore-scripts=false`.

---

## Quick Start — Local (Primary)

### Option A — one-liner setup

```bash
# 1. Install all PHP + JS deps, copy .env, generate key, run migrations, build assets
composer setup

# 2. Create the SQLite database file if it does not already exist
touch database/database.sqlite

# 3. Seed the database — see the seed-size warning below before running the default
php artisan db:seed

# 4. Start all dev processes (server + queue + log tail + Vite HMR) concurrently
composer dev
```

### Option B — manual step-by-step

```bash
composer install

cp .env.example .env
php artisan key:generate

# Create the SQLite file (Laravel will not create it automatically)
touch database/database.sqlite

php artisan migrate

# See seed-size warning below
php artisan db:seed

npm install
npm run dev        # Vite HMR for development
# or
npm run build      # production build
```

### Seed-size warning

The default seed is **1,250,000 events**, producing a roughly **2.5 GB SQLite file** and taking
several minutes to write. For evaluation or development, seed a much smaller dataset:

```bash
# ~5,000 rows, fast — recommended for reviewers
SEED_ROWS=5000 php artisan db:seed

# Run it again cleanly (truncate first)
php artisan migrate:fresh && SEED_ROWS=5000 php artisan db:seed
```

`SEED_ROWS` is read directly by `EventSeeder` (`database/seeders/EventSeeder.php:67`).

### Storage symlink (needed once images are implemented)

```bash
php artisan storage:link
```

This maps `public/storage` → `storage/app/public` for locally served event images.
The feature is not yet built; run this command once it lands.

---

## Alternative: Docker (Laravel Sail)

`laravel/sail` is a dev dependency. Once `composer install` has run:

```bash
# Start all containers (first run pulls images)
./vendor/bin/sail up -d

# Run migrations
./vendor/bin/sail artisan migrate

# Seed (use SEED_ROWS to keep it manageable)
SEED_ROWS=5000 ./vendor/bin/sail artisan db:seed

# Build frontend assets
./vendor/bin/sail npm run build
# or for Vite HMR
./vendor/bin/sail npm run dev
```

The app will be available at `http://localhost` (port 80 by default via Sail's nginx).
Queue worker and scheduler need to be started separately if they are not in the Sail
`docker-compose.yml`; alternatively use `./vendor/bin/sail artisan queue:listen`.

---

## Running the App

### Dev server

```bash
composer dev
```

This starts four concurrent processes:

| Process | What it does |
|---|---|
| `php artisan serve --host=localhost` | App at **http://localhost:8000** |
| `php artisan queue:listen --tries=1 --timeout=0` | Processes queued jobs (emails) |
| `php artisan pail --timeout=0` | Live log tail (see emails in `MAIL_MAILER=log`) |
| `npm run dev` | Vite HMR dev server |

### Key routes

| URL | Name | Status |
|---|---|---|
| `http://localhost:8000/` | `home` | Redirects to `/events` |
| `http://localhost:8000/events` | `events.index` | Working — debug table view |
| `http://localhost:8000/events/data` | `events.data` | Working — JSON, infinite scroll |
| `http://localhost:8000/events/{uuid}` | `events.show` | Working — raw payload dump |
| `http://localhost:8000/events-visual-1` | `events.visual1` | **Build target — stub only** |
| `http://localhost:8000/events-visual-2` | `events.visual2` | **Build target — stub only** |
| `http://localhost:8000/dashboard` | `dashboard` | Working — auth dashboard |

The two Visual pages (`/events-visual-1`, `/events-visual-2`) are the primary deliverables of
the coding test. They are currently empty stubs.

### Queue worker and scheduler

The queue worker (`queue:listen`) is included in `composer dev`. Confirmation and reminder emails
are queued — the worker must be running for them to be dispatched.

Reminder emails (3 days before / 24 hours before an event) also require a running scheduler
(`php artisan schedule:work`). The scheduler is not yet wired into `composer dev`; it will be
added when the reminder feature is built.

---

## Quality & Tests

### Run the full quality suite

```bash
composer test
```

This runs in order: `artisan config:clear`, Pint lint check, PHPStan type check, then Pest tests.

### Individual checks

```bash
# PHP style lint (check only — no changes)
composer lint:check

# PHP style lint (auto-fix)
composer lint

# PHP static analysis (PHPStan level 7)
composer types:check

# Run Pest tests only
php artisan test

# JS/TS lint (ESLint)
npm run lint:check

# JS/TS lint + auto-fix
npm run lint

# TS type check
npm run types:check

# Prettier format check
npm run format:check
```

### Test database

Tests use SQLite `:memory:` (configured in `phpunit.xml`). They never touch the seeded file
database. Mail is `array`, queue is `sync`, and sessions/cache are `array` in tests, so
everything runs in-process without external dependencies.

Current test count: 12 test files (auth, settings, event listing, examples). There are no tests
yet for attendees, images, emails, or reminders — those will be added with each feature.

---

## Configuration Notes

Key `.env` values reviewers should be aware of:

| Key | Default | Notes |
|---|---|---|
| `APP_ENV` | `local` | |
| `APP_DEBUG` | `true` | |
| `APP_URL` | `http://localhost` | Artisan serve runs on port 8000 |
| `DB_CONNECTION` | `sqlite` | File at `database/database.sqlite` |
| `MAIL_MAILER` | `log` | Emails are written to `storage/logs`, **not actually sent**. View them with `artisan pail` or by reading the log file. |
| `QUEUE_CONNECTION` | `database` | Jobs stored in the `jobs` table; requires `queue:listen` |
| `SESSION_DRIVER` | `database` | Requires the `sessions` table (created by migration) |
| `CACHE_STORE` | `database` | Requires the `cache` table (created by migration) |
| `FILESYSTEM_DISK` | `local` | Event images will use the `public` disk (`storage/app/public`) |
| `SEED_ROWS` | `1250000` | Override to seed a smaller dataset (e.g. `SEED_ROWS=5000`) |

> **Emails in dev:** because `MAIL_MAILER=log`, every confirmation and reminder email is written
> to `storage/logs/laravel.log`. Run `artisan pail` (included in `composer dev`) to watch them
> appear in real time.

---

## Project Structure

```
lhp-coding-test-main/
├── app/
│   ├── Http/Controllers/      # EventController, Settings controllers
│   ├── Models/                # Event, User (UUID PK, JSON payload)
│   ├── Providers/             # AppServiceProvider, FortifyServiceProvider
│   └── Actions/Fortify/       # Auth actions (CreateNewUser, etc.)
├── database/
│   ├── migrations/            # Schema: events, users, jobs, sessions, passkeys
│   ├── seeders/               # EventSeeder (1.25M rows, SEED_ROWS override)
│   └── factories/             # EventFactory (used by tests)
├── resources/
│   ├── css/                   # app.css (Tailwind v4 entry)
│   └── js/
│       ├── app.ts             # Inertia bootstrap + layout resolver
│       ├── pages/             # Vue pages (Events/*, auth/*, settings/*, Dashboard)
│       ├── layouts/           # AppLayout, AuthLayout, SettingsLayout
│       ├── components/        # App chrome + ~40 shadcn-vue/reka-ui UI primitives
│       ├── composables/       # useAppearance, useCurrentUrl, etc.
│       └── types/             # TypeScript type definitions
├── routes/
│   ├── web.php                # Event routes + visual page stubs + dashboard
│   ├── settings.php           # Authenticated settings routes
│   └── console.php            # Scheduled commands (none yet; reminders land here)
├── tasks/                     # Per-task working docs (research, design, planning)
│   └── 1-codebase-research/   # RESEARCH.md — authoritative codebase audit
├── .claude/                   # Project state and agent configuration
├── CODING_TEST.md             # The original test requirements
└── README.md                  # This file
```

---

## Approach & Decisions (Living — Updated as Features Land)

This section summarises the current understanding of the codebase and the intended approach for
each requirement. It will be updated as work is delivered. The `tasks/<n>-<name>/` directories
contain detailed per-feature research, design, and planning docs.

### Data model

The `events` table has an important quirk: `created_time` is a **UNIX timestamp representing the
event start time** — not the row creation timestamp. Most event data (name, description, venue,
schedule, pricing, tags) lives in a JSON `payload` column. The only indexed column is `status`.

There is no address, no image, and no attendee table. The dataset (~1.25M rows, ~2.5 GB) clusters
around ~78 real city coordinates, which matters for geocoding and filtering strategy.

See `tasks/1-codebase-research/RESEARCH.md` for the full audit including all known quirks.

### Requirement areas and current status

| Requirement | Status | Approach (plan) |
|---|---|---|
| **Visual page 1** (distinct layout A) | Not yet built | Card grid or timeline layout; Tailwind v4 + reka-ui components; infinite scroll or paginated load; date/location filters wired to backend |
| **Visual page 2** (distinct layout B) | Not yet built | Meaningfully different from page 1 (e.g. map view or calendar); same data pipeline |
| **Images (2+ per event, local)** | Not yet built | New `event_images` table; `public` disk + `storage:link`; seed with shared placeholder files to avoid bloating the DB |
| **Addresses from lat/lng** | Not yet built | Precompute city from nearest `CITY_ANCHORS` (offline, fast, matches seeder clustering); store denormalized; do not geocode per request at 1.25M-row scale |
| **Date/time + timezones** | Not yet built | Display in UTC or derive from coordinates; no JS date lib currently installed — one will be added (dayjs or date-fns) |
| **Date + location filtering** | Not yet built (date filter plumbed but ignored server-side) | Add `created_time` index; add backend query logic for date range and location/city; fix known `aplyFilters` typo in `Events/Index.vue:148` |
| **Tailwind styling** | Fully wired, not yet used on visual pages | Use Tailwind v4 for all new UI |
| **Animations** | `tw-animate-css` installed | Tasteful transitions on cards, filters, page load; avoid overdoing |
| **Attendee registration** | Not yet built | New `attendees` table + model + `Event` relation; registration endpoint + UI; decide auth model (logged-in user vs. email capture) |
| **Confirmation email** | Not yet built | Mailable/Notification dispatched on registration; queued; visible in `storage/logs` via `pail` |
| **Reminder emails (3 days + 24 hours)** | Not yet built | Scheduled command in `routes/console.php`; scans upcoming events; dispatches queued jobs with idempotency guard; requires `schedule:work` and `queue:listen` |

### Key constraints to keep in mind

- **Scale:** 1.25M rows, 2 GB+ DB. Every query against the listing must be index-backed.
  Avoid unbounded `SELECT *` with full `payload` hydration. Pagination or cursor-based loading is
  required for the visual pages.
- **JSON payload:** filtering or sorting by payload fields (name, date, location) means either
  SQLite JSON extraction (slow at scale) or denormalized indexed columns. Prefer denormalization
  for anything that needs to be queried frequently.
- **Payload type inconsistency:** the seeder stores some payload values as strings; the factory
  stores them as numbers. Frontend and backend code must not assume numeric types when reading
  `payload`.
- **Auth on event routes:** event routes are currently public. Attendee registration will need a
  decision: require login, or capture an email address without a full account.

---

## Verification Checklist

A reviewer can use the following checklist to exercise each delivered feature.
Currently only baseline functionality is verifiable; the list grows with each feature.

### Baseline (working now)

- [ ] `composer setup` completes without errors
- [ ] `SEED_ROWS=5000 php artisan db:seed` seeds 5,000 events (check row count: `php artisan tinker --execute="echo \App\Models\Event::count();"`)
- [ ] `composer dev` starts and http://localhost:8000 redirects to `/events`
- [ ] `/events` renders the event table; scrolling loads more rows; the load-stats footer shows
- [ ] `/events?status=published` filters by status (via URL; the Filter button has a known typo bug)
- [ ] `/events/{uuid}` shows a raw payload dump for a single event
- [ ] `composer test` passes all existing tests with no failures

### Pending — to be checked once features are built

- [ ] `/events-visual-1` renders a full-featured event browsing page (not a stub)
- [ ] `/events-visual-2` renders a visually distinct second browsing page
- [ ] Each event card/row shows: title, description, image(s), human-readable location, formatted date/time
- [ ] Images are served from `/storage/...` (local, not hotlinked)
- [ ] Date range filter narrows results correctly
- [ ] Location/city filter narrows results correctly
- [ ] Registering attendance adds a row to the attendees table
- [ ] A confirmation email appears in `storage/logs/laravel.log` (or via `pail`) on registration
- [ ] With `schedule:work` and `queue:listen` running, reminder emails appear in the log at the
  3-day and 24-hour marks before an event
