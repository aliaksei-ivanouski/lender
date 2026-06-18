# LHP Coding Test — Event Visuals

A Laravel + Vue/Inertia application seeded with a large, realistic events dataset. This repository
is a take-home coding test; the full requirements are in [CODING_TEST.md](./CODING_TEST.md).

The task was to build two distinct event-browsing pages (Event Visuals 1 & 2), add image and
address support, implement date/location filtering, and wire up attendee registration with
confirmation and reminder emails — all on top of the existing Laravel 13 starter-kit scaffolding.
All seven user stories are now fully delivered.

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
| Mail | `array` driver in dev (not sent externally); a concise "Mail sent" line is written to `storage/logs/laravel.log` |
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

### One command — recommended

```bash
./bin/setup        # or equivalently: composer setup
```

Then start the app:

```bash
php artisan serve
# open http://127.0.0.1:8000/events-visual-1
```

For HMR development (server + queue + log tail + Vite + scheduler all at once):

```bash
composer dev
```

**What `./bin/setup` does (all steps are idempotent — safe to re-run):**

1. Installs PHP dependencies (`composer install`) if `vendor/` is missing.
2. Copies `.env.example` → `.env` if `.env` is missing.
3. Generates `APP_KEY` only if not already set (never regenerates an existing key).
4. Touches `database/database.sqlite` if it does not exist.
5. Installs JS dependencies using a modern npm. If the machine's global `npm` is older than v7
   (e.g. npm 6 ships with some older Node distributions), the script automatically invokes
   `npx npm@latest` instead — this preserves `lockfileVersion 3` and avoids silent Vite failures.
6. Builds frontend assets via `npx vite build` (works regardless of local npm version).
7. Runs `php artisan migrate --force` (idempotent).
8. Seeds events, cities, and images (see [Seeding](#seeding) below). Default: 2,000 events.
   Override with `SEED_ROWS`:
   ```bash
   SEED_ROWS=5000 ./bin/setup
   ```
9. Runs `php artisan events:geocode-cities` to backfill human-readable city names.
10. Creates the storage symlink (`php artisan storage:link`) for locally served images.

> **Default seed size:** `./bin/setup` seeds only 2,000 events. The default when running
> `php artisan db:seed` directly (without `SEED_ROWS`) is 1,250,000 rows (~2.5 GB). Always
> use `./bin/setup` or `SEED_ROWS=N php artisan db:seed` for local/review use.

### Manual / step-by-step

If you prefer to run each step yourself:

```bash
composer install

cp .env.example .env
php artisan key:generate

# Create the SQLite file (Laravel will not create it automatically)
touch database/database.sqlite

php artisan migrate

# Seed a small dataset (default is 1.25M rows — always specify SEED_ROWS locally)
SEED_ROWS=2000 php artisan db:seed

# Backfill city names for event addresses
php artisan events:geocode-cities

# Symlink storage for locally served images
php artisan storage:link

# Build frontend assets (use npx vite build to avoid issues with npm < 7)
npx vite build
# or for Vite HMR during development:
npx vite
```

To seed a different size:

```bash
# ~5,000 rows — recommended for reviewers
SEED_ROWS=5000 php artisan db:seed

# Start fresh
php artisan migrate:fresh && SEED_ROWS=5000 php artisan db:seed
```

`SEED_ROWS` is read directly by `EventSeeder` (`database/seeders/EventSeeder.php`).

---

## Seeding

All seeders are **idempotent** — safe to re-run without duplicating data.

| Seeder | What it does |
|---|---|
| `DatabaseSeeder` | Orchestrates the others; creates the test user (`test@example.com` / `password`) if it does not already exist |
| `CitySeeder` | Populates the `cities` lookup table used by the reverse geocoder |
| `EventSeeder` | Tops up the `events` table to the `SEED_ROWS` target (skips rows that already exist) |
| `EventImageSeeder` | Copies 8 PNG placeholders to the public disk and assigns 2 images per event for any event that has none |

**Test user credentials:**

| Field | Value |
|---|---|
| Email | `test@example.com` |
| Password | `password` |

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

This starts **five** concurrent processes:

| Process | What it does |
|---|---|
| `php artisan serve --host=localhost` | App at **http://localhost:8000** |
| `php artisan queue:listen --tries=1 --timeout=0` | Processes queued jobs (emails) |
| `php artisan pail --timeout=0` | Live log tail (see concise "Mail sent" lines as emails are dispatched) |
| `npx vite` | Vite HMR dev server (avoids npm < 7 issue) |
| `php artisan schedule:work` | Runs the Laravel scheduler every minute — fires reminder emails automatically |

### Key routes

| URL | Name | Status |
|---|---|---|
| `http://localhost:8000/` | `home` | Redirects to `/events` |
| `http://localhost:8000/events` | `events.index` | Working — debug table view |
| `http://localhost:8000/events/data` | `events.data` | Working — JSON, infinite scroll |
| `http://localhost:8000/events/{uuid}` | `events.show` | Working — event detail with attendee list |
| `http://localhost:8000/events-visual-1` | `events.visual1` | **Delivered — animated card grid** |
| `http://localhost:8000/events-visual-2` | `events.visual2` | **Delivered — Leaflet clustered map** |
| `http://localhost:8000/dashboard` | `dashboard` | Working — auth dashboard |

### Queue worker and scheduler

The queue worker (`queue:listen`) and the scheduler (`schedule:work`) are both included in
`composer dev`. Confirmation and reminder emails are queued — the worker must be running for them
to be dispatched. The scheduler fires `events:send-reminders` every hour while `composer dev` is
running.

**Production:** `schedule:work` is a dev-only helper. In production, add the standard cron entry:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

---

## Delivered Features

### Event Visual 1 — Animated Card Grid (`/events-visual-1`)

An animated card grid layout showing event cards with title, description, image, human-readable
location, and formatted date/time. Supports:

- Date range filter with a custom MM/DD/YYYY US date picker (min/max bounds enforced).
- Location/city filter.
- Infinite scroll with dynamic pagination.

### Event Visual 2 — Leaflet Clustered Map (`/events-visual-2`)

A full-screen Leaflet + OpenStreetMap map using `leaflet.markercluster`. Each marker represents
an event; clusters expand on zoom. Supports the same date and location filters and
infinite-scroll/dynamic pagination as Visual 1. Clicking a marker opens an event popup.

### Images

Each event has 2+ images served locally from the `public` disk (`storage/app/public/event-images/`).
URLs are root-relative (e.g. `/storage/event-images/1.png`) via `config/filesystems.php`
`'url' => '/storage'`. The `storage:link` symlink is required — `./bin/setup` runs it
automatically.

### Reverse Geocoding

Event latitude/longitude coordinates are converted to a human-readable city/address using a
DB-backed geocoder (`DatabaseReverseGeocoder` adapter). The implementation uses a bounding-box
prefilter against the `cities` table, picks the nearest city by distance, and caches results for
24 hours — no external API calls at runtime.

### Timezones

Event dates and times are formatted in the event's local timezone via
`app/Services/TimezoneService.php`. The `created_time` column is a UNIX timestamp representing the
**event start time** (not the row creation time).

### Attendee Registration

Fortify-authenticated users can register for or deregister from events via
`POST /events/{event}/registrations` and `DELETE /events/{event}/registrations`. Registration is
idempotent (`firstOrCreate` + `wasRecentlyCreated`). The attendee list is visible on the event
detail page. A queued `RegistrationConfirmationNotification` email is dispatched on successful
registration.

### Confirmation Emails

A queued confirmation email is sent to the attendee on registration. With `MAIL_MAILER=array`
(the default), a concise `Mail sent` log line (to + subject) appears in `storage/logs/laravel.log`
and via `php artisan pail`. Switch to `MAIL_MAILER=log` to inspect the full HTML body.

### Reminder Emails — `events:send-reminders`

The `php artisan events:send-reminders` command scans upcoming events and dispatches queued
`EventReminderNotification` emails in two passes:

| Pass | Trigger |
|---|---|
| 3-day reminder | Event starts within the next 3 days and `reminder_3day_sent_at` is null |
| 24-hour reminder | Event starts within the next 24 hours and `reminder_24hour_sent_at` is null |

Idempotency is enforced by the `reminder_3day_sent_at` and `reminder_24hour_sent_at` timestamp
columns — re-running the command never sends a duplicate.

The command runs **hourly** via the Laravel scheduler (`routes/console.php`):

```php
Schedule::command('events:send-reminders')->hourly();
```

To trigger it manually:

```bash
php artisan events:send-reminders
```

While `composer dev` is running, the scheduler fires automatically every minute via
`schedule:work`, so reminders are dispatched without any manual intervention in local development.

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

Current test count: **95 tests passing** (Pest). PHPStan level 7: 0 errors. Pint: clean.

---

## Configuration Notes

Key `.env` values reviewers should be aware of:

| Key | Default | Notes |
|---|---|---|
| `APP_ENV` | `local` | |
| `APP_DEBUG` | `true` | |
| `APP_URL` | `http://localhost` | Artisan serve runs on port 8000 |
| `DB_CONNECTION` | `sqlite` | File at `database/database.sqlite` |
| `MAIL_MAILER` | `array` | Emails are **not sent externally**. The `array` driver discards the message body. A concise `Mail sent` log line (to + subject) is written to `storage/logs/laravel.log` when the queue worker processes each email. To inspect full HTML bodies, temporarily set `MAIL_MAILER=log`. |
| `QUEUE_CONNECTION` | `database` | Jobs stored in the `jobs` table; requires `queue:listen` |
| `SESSION_DRIVER` | `database` | Requires the `sessions` table (created by migration) |
| `CACHE_STORE` | `database` | Requires the `cache` table (created by migration) |
| `FILESYSTEM_DISK` | `local` | Event images use the `public` disk (`storage/app/public`) served via the `storage:link` symlink |
| `SEED_ROWS` | `1250000` | Override to seed a smaller dataset (e.g. `SEED_ROWS=5000`) |

> **Emails in dev:** `MAIL_MAILER=array` discards the message body — no HTML is written to the
> log. Instead, a concise `Mail sent` line (to address + subject) is written to
> `storage/logs/laravel.log` for every email the queue worker dispatches. The subject line conveys
> the reason (e.g. "You're registered: {Event}"). Run `artisan pail` (included in `composer dev`)
> to watch these lines appear in real time. To inspect full HTML bodies temporarily, set
> `MAIL_MAILER=log` in `.env` and restart the worker.

---

## Project Structure

```
lhp-coding-test-main/
├── app/
│   ├── Http/Controllers/      # EventController, RegistrationController, Settings controllers
│   ├── Models/                # Event, EventImage, Attendee, User (UUID PK, JSON payload)
│   ├── Services/              # TimezoneService, DatabaseReverseGeocoder
│   ├── Providers/             # AppServiceProvider, FortifyServiceProvider
│   └── Actions/Fortify/       # Auth actions (CreateNewUser, etc.)
├── database/
│   ├── migrations/            # Schema: events, event_images, attendees, users, jobs, sessions
│   ├── seeders/               # DatabaseSeeder, EventSeeder, EventImageSeeder, CitySeeder
│   │   └── images/            # 1.png–8.png placeholder event images
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
│   ├── web.php                # Event routes, visual pages, registration, dashboard
│   ├── settings.php           # Authenticated settings routes
│   └── console.php            # Scheduled commands (events:send-reminders → hourly)
├── tasks/                     # Per-task working docs (research, design, planning)
│   └── 1-codebase-research/   # RESEARCH.md — authoritative codebase audit
├── .claude/                   # Project state and agent configuration
├── CODING_TEST.md             # The original test requirements
└── README.md                  # This file
```

---

## Data Model Notes

The `events` table has an important quirk: `created_time` is a **UNIX timestamp representing the
event start time** — not the row creation timestamp. Most event data (name, description, venue,
schedule, pricing, tags) lives in a JSON `payload` column. The dataset (~1.25M rows, ~2.5 GB)
clusters around ~78 real city coordinates.

Denormalized indexed columns (`city`, `lat`, `lng`, `start_date`) are used for all filtered
queries to avoid slow JSON extraction at scale.

See `tasks/1-codebase-research/RESEARCH.md` for the full audit including all known quirks.

---

## Verification Checklist

A reviewer can use the following checklist to exercise each delivered feature.

### Setup & baseline

- [ ] `./bin/setup` completes without errors
- [ ] `SEED_ROWS=5000 php artisan db:seed` seeds 5,000 events (check: `php artisan tinker --execute="echo \App\Models\Event::count();"`)
- [ ] `composer dev` starts all five processes and http://localhost:8000 redirects to `/events`
- [ ] `/events` renders the event table; scrolling loads more rows
- [ ] `composer test` passes all 95 tests with no failures

### Visual pages

- [ ] `/events-visual-1` renders the animated card grid with event cards (title, image, location, date)
- [ ] `/events-visual-2` renders the Leaflet map with clustered markers
- [ ] Date range filter narrows results on both pages
- [ ] Location/city filter narrows results on both pages
- [ ] Infinite scroll loads additional events as the user scrolls / pans

### Images

- [ ] Event images are served from `/storage/event-images/...` (local, not hotlinked)
- [ ] Each event card shows at least 2 images

### Addresses & timezones

- [ ] Each event shows a human-readable city/address (not raw lat/lng)
- [ ] Event dates are displayed in the event's local timezone

### Attendee registration

- [ ] Logging in with `test@example.com` / `password` works
- [ ] Clicking "Register" on an event detail page adds a row to the attendees table
- [ ] Re-clicking "Register" does not create a duplicate
- [ ] A confirmation email line appears in `storage/logs/laravel.log` (or via `php artisan pail`)

### Reminder emails

- [ ] `php artisan events:send-reminders` runs without error
- [ ] Running it twice does not send duplicate reminders (idempotency)
- [ ] With `composer dev` running, reminder log lines appear automatically for events within the 3-day and 24-hour windows
