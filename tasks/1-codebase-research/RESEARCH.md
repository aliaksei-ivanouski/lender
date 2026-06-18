# Codebase Research — LHP Coding Test (Event Visuals)

Research date: 2026-06-18. Read-only audit. All paths are relative to the repo root
`/Users/aliaksei.ivanouski/projects/test/lhp-coding-test-main`.

---

## 1. Overview & Purpose

This is a **Laravel + Vue/Inertia starter-kit** seeded with a large, realistic **events**
dataset. The repository is a take-home **coding test**. The candidate must build out two
event-browsing pages and a registration/email feature on top of the existing scaffolding.

### What `CODING_TEST.md` asks for (`CODING_TEST.md:1-41`)

Build **Event Visuals 1** and **Event Visuals 2** as **two distinct layout styles**
(e.g. card grid, map, calendar, timeline — they must not look like the same page twice).
Each event must present: **Title + description**, **Location + date/time**, **an image**.

Requirements:
- **Images** (`:16-18`) — events have none yet. Add support **end to end**, **2+ images per
  event**, served **locally** (no external/hotlinked URLs). Placeholder reuse is allowed.
- **Addresses** (`:19-20`) — events only carry lat/lng; turn into a human-readable location.
- **Date & time** (`:21-23`) — events are global; handle timezones sensibly (approach is open).
- **Filtering** (`:23-24`) — any style, but must at minimum **filter by date and by location**.
- **Tailwind** (`:25`) for styling.
- **Animations** (`:26`) where they make sense; don't overdo it.
- **Attendees & emails** (`:29-33`):
  - Let people register **interest/attendance** (an attendee list).
  - On add, **email a confirmation**.
  - Send **reminder emails as the event approaches — 3 days before AND 24 hours before**.
- **Notes** (`:35-40`) — work against the realistic seeded dataset as-is; keep code clean;
  include a short note on decisions. Quality over quantity.

---

## 2. Tech Stack & Tooling

### Backend (`composer.json`)
| Package | Version | Role |
|---|---|---|
| `php` | `^8.3` | Runtime |
| `laravel/framework` | `^13.7` | Framework |
| `inertiajs/inertia-laravel` | `^3.0` | Server-side Inertia adapter |
| `laravel/fortify` | `^1.37.2` | Headless auth: login, registration, password reset, email verification, **2FA**, **passkeys** |
| `laravel/wayfinder` | `^0.1.14` | Generates typed TS route/action helpers from PHP routes (used in Vue, e.g. `dashboard()`) |
| `laravel/chisel` | `^0.1.0` | Laravel tooling/scaffolding helper |
| `laravel/tinker` | `^3.0` | REPL |
| **dev** | | |
| `pestphp/pest` | `^4.7` | Test runner (+ `pest-plugin-laravel`, `pest-plugin-drift`) |
| `larastan/larastan` | `^3.9` | PHPStan for Laravel (level 7) |
| `laravel/pint` | `^1.27` | Code style (`laravel` preset, `pint.json`) |
| `laravel/pail` | `^1.2.5` | Live log tailing (used in `composer dev`) |
| `laravel/pao` | `^1.0.6` | Laravel dev/automation helper |
| `laravel/sail` | `^1.53` | Docker dev env |
| `nunomaduro/collision` | `^8.9.3` | Pretty CLI errors |
| `fakerphp/faker` | `^1.24` | Factory fake data |

### Frontend (`package.json`)
- **Vue `^3.5`**, **Inertia `@inertiajs/vue3 ^3.0`**, **TypeScript `^5.2`**.
- **Tailwind CSS v4** (`tailwindcss ^4.1`, `@tailwindcss/vite`), `tw-animate-css ^1.2.5`
  (animation utilities — relevant to the "animations" requirement).
- **Vite `^8.0`** (`vite.config.ts`), `laravel-vite-plugin ^3.0`, `@inertiajs/vite`,
  `@laravel/vite-plugin-wayfinder`.
- UI: **reka-ui `^2.9`** (Radix-Vue successor) — the `ui/` components are shadcn-vue style.
  `class-variance-authority`, `clsx`, `tailwind-merge`, `@lucide/vue` (icons),
  `vue-sonner` (toasts), `vue-input-otp`, `@laravel/passkeys`, `@vueuse/core`.
- **Note: there is NO map library, NO date library (date-fns/luxon/dayjs), NO image/upload
  helpers installed** — these will need to be added for the coding test.

### Lint / format / typecheck / test
- ESLint flat config (`eslint.config.js`), Prettier (`.prettierrc`, `prettier-plugin-tailwindcss`).
- `npm run types:check` → `vue-tsc --noEmit`. `npm run lint` / `format`.
- PHPStan level 7 (`phpstan.neon`), Pint laravel preset (`pint.json`).
- **PHPUnit/Pest** (`phpunit.xml`): test DB is **SQLite `:memory:`**, `MAIL_MAILER=array`,
  `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`, `CACHE_STORE=array`, `BCRYPT_ROUNDS=4`.
  So tests never touch the 1.25M-row file DB and emails/queues run synchronously in-memory.
- `composer ci:check` = lint:check + format:check + types:check + test. `composer test`
  runs config:clear, pint --test, phpstan, then `artisan test`.

### How it runs
- **`composer dev`** (`composer.json:54-57`) runs 4 concurrent processes:
  `php artisan serve`, **`php artisan queue:listen`**, `php artisan pail` (logs), `npm run dev`.
  The queue listener is already wired into the dev script — important for the reminder-email work.
- **`composer setup`** (`:46-53`): install, copy `.env`, key:generate, migrate, npm install, build.
- **PHP `^8.3`**, **Node** (Vite 8 → Node 20+ recommended). Package manager: repo has both
  `package-lock.json` and `pnpm-workspace.yaml` + `.npmrc`; **CI uses `npm i`** (`tests.yml:46`).

### CI (`.github/workflows/`)
- `tests.yml`: `npm i` → `composer install` → copy `.env` → key:generate → `npm run build` →
  `composer types:check` → `php artisan test`. **Does not set `SEED_ROWS` / does not seed** —
  it relies on the in-memory test DB, so CI is fast.
- `lint.yml`: linting pipeline.

---

## 3. Application Bootstrap & Request Lifecycle

- **`public/index.php`** — standard Laravel 13 front controller.
- **`bootstrap/app.php`** (`:11-30`):
  - Routing: `web.php`, console `console.php`, health endpoint `/up`.
  - Middleware: `encryptCookies(except: ['appearance', 'sidebar_state'])`; appends to the `web`
    group: `HandleAppearance`, `HandleInertiaRequests`, `AddLinkHeadersForPreloadedAssets`.
  - Exceptions: render JSON when path matches `api/*` (no `api/*` routes exist yet).
- **`bootstrap/providers.php`** — registers `AppServiceProvider`, `FortifyServiceProvider`.
- **`AppServiceProvider`** (`app/Providers/AppServiceProvider.php:32-49`):
  - `Date::use(CarbonImmutable::class)` — all dates are CarbonImmutable.
  - `DB::prohibitDestructiveCommands(isProduction)`.
  - Strong password defaults **only in production** (min 12, mixed case, symbols, uncompromised);
    **no password complexity in local/test** (`Password::defaults` returns `null`).
- **`FortifyServiceProvider`** (`app/Providers/FortifyServiceProvider.php`):
  - Maps Fortify actions (`CreateNewUser`, `ResetUserPassword`).
  - Defines all auth views as Inertia pages (`auth/Login`, `auth/Register`, …).
  - Rate limiters: `login` (5/min by email+ip), `two-factor` (5/min), `passkeys` (10/min).
- **`HandleInertiaRequests`** (`app/Http/Middleware/HandleInertiaRequests.php:36-46`) — shared
  Inertia props: `name`, `auth.user` (the current `$request->user()`), `sidebarOpen`.
  **There is no shared `flash` prop here** — flash toasts come via Inertia's `flash` event
  (see `flashToast.ts`) and `Inertia::flash(...)` (used in `ProfileController`).
- **`HandleAppearance`** — shares `appearance` cookie (light/dark/system) to Blade views.

---

## 4. Routing

### `routes/web.php`
| Method | URI | Handler | Name | Auth |
|---|---|---|---|---|
| GET | `/` | redirect → `/events` | `home` | none |
| GET | `events` | `EventController@index` | `events.index` | **none** |
| GET | `events/data` | `EventController@data` | `events.data` | **none** (JSON, infinite scroll) |
| GET | `events/{event}` | `EventController@show` | `events.show` | none (route-model binding by UUID) |
| GET | `events-visual-1` | `Inertia::render('Events/VisualOne')` | `events.visual1` | none — **the build target** |
| GET | `events-visual-2` | `Inertia::render('Events/VisualTwo')` | `events.visual2` | none — **the build target** |
| GET | `dashboard` | `Inertia::render('Dashboard')` | `dashboard` | none (sidebar links here) |

`require __DIR__.'/settings.php'` at the end.

### `routes/settings.php` (all behind `auth`, some `auth+verified`)
| Method | URI | Handler | Name |
|---|---|---|---|
| GET | `settings` | redirect → `/settings/profile` | — |
| GET | `settings/profile` | `ProfileController@edit` | `profile.edit` |
| PATCH | `settings/profile` | `ProfileController@update` | `profile.update` |
| DELETE | `settings/profile` | `ProfileController@destroy` | `profile.destroy` (auth+verified) |
| GET | `settings/security` | `SecurityController@edit` (RequirePassword) | `security.edit` |
| PUT | `settings/password` | `SecurityController@update` (throttle:6,1) | `user-password.update` |
| GET | `settings/appearance` | Inertia `settings/Appearance` | `appearance.edit` |
| GET | `.well-known/passkey-endpoints` | closure → JSON | `well-known.passkeys` |

Fortify also auto-registers `/login`, `/register`, `/forgot-password`, `/reset-password`,
`/two-factor-challenge`, `/user/confirm-password`, etc. (`prefix => ''`, `middleware => ['web']`).

### `routes/console.php`
Only the stock `inspire` command. **No scheduled commands defined yet** — the reminder-email
schedule will be added here (or via `app/Console`).

---

## 5. Data Model & IMPORTANT QUIRKS

### `events` table (`database/migrations/2024_02_01_000000_create_events_table.php`)
| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUID primary key (string) |
| `user_id` | `foreignId` → users, cascade delete | event organizer |
| `type` | `string` | category: concert, conference, meetup, … |
| `status` | `string` | draft / published / cancelled / sold_out — **indexed** (`:22`) |
| `created_time` | `unsignedBigInteger` nullable | **⚠️ UNIX timestamp = the event START time. NOT `created_at`.** |
| `latitude` | `decimal(10,7)` nullable | |
| `longitude` | `decimal(10,7)` nullable | |
| `payload` | `longText` | **JSON blob holding most event data** (name, description, venue, schedule, pricing, tags) |
| `created_at`/`updated_at` | timestamps | real row timestamps |

The **only index** is on `status`. There is **no index on `created_time`**, lat/lng, or `type`.
Ordering the listing by `created_time` (the controller does this) is therefore an unindexed
sort over up to 1.25M rows.

### `Event` model (`app/Models/Event.php`)
- `use HasFactory, HasUuids;` — `newUniqueId()` returns a v4 UUID (`:23-25`).
- `$guarded = []` (mass-assignable).
- Casts (`:17-21`): `payload => array`, `latitude => float`, `longitude => float`.
- Relation: `user(): BelongsTo` (`:28-31`). **No `attendees` relation exists yet** — must be added.

### CRITICAL QUIRKS for the coding test
1. **`created_time` is a UNIX timestamp (event start), not creation time.** Display logic and
   date filtering must treat it as the event start. (Also duplicated in `payload.schedule.starts_at`.)
2. **Most event data lives in the JSON `payload`** — `name`, `description`, `organizer`, `venue`,
   `location.{lat,lng}`, `schedule.{starts_at,ends_at}`, `pricing`, `tags`, `notes`. Querying/
   filtering by these means JSON extraction or denormalization.
3. **No image column / no images at all** — must be added end to end (storage + serving + 2+/event).
4. **Only lat/lng, no address** — reverse-geocoding (or coordinate→city lookup) needed.
5. **UUID PK** — route-model binding is by UUID string (`events/{event}` works directly).

### Other migrations
- `0001_01_01_000000_create_users_table.php` — `users`, `password_reset_tokens`, `sessions`
  (sessions table used because `SESSION_DRIVER=database`).
- `0001_01_01_000001_create_cache_table.php` — `cache`, `cache_locks` (`CACHE_STORE=database`).
- `0001_01_01_000002_create_jobs_table.php` — **`jobs`, `job_batches`, `failed_jobs`**
  (`QUEUE_CONNECTION=database`) → **the queue infrastructure for reminder emails already exists.**
- `2024_01_01_000000_create_passkeys_table.php` — WebAuthn credentials.
- `2025_08_14_170933_add_two_factor_columns_to_users_table.php` — `two_factor_secret`,
  `two_factor_recovery_codes`, `two_factor_confirmed_at`.

### `User` model (`app/Models/User.php`)
- `Authenticatable implements PasskeyUser`; traits `HasFactory, Notifiable,
  PasskeyAuthenticatable, TwoFactorAuthenticatable` (`:35`).
- Attribute config via PHP 8 attributes: `#[Fillable(['name','email','password'])]`,
  `#[Hidden([...secrets...])]` (`:30-31`).
- Casts: `email_verified_at => datetime`, `password => hashed`,
  `two_factor_confirmed_at => datetime`. `Notifiable` is present → mailables/notifications
  can be sent to a user directly.

---

## 6. The Events Feature — Current State

### `EventController` (`app/Http/Controllers/EventController.php`)
- **`index`** (`:14-23`) — renders `Events/Index` with `filters` (`status`, `from` defaulting to
  `'2023-01-01'`) and `statuses` list. Note: `from` is plumbed into props **but never used** by
  the query (see below).
- **`data`** (`:25-36`) — JSON endpoint for infinite scroll. Returns
  `{ data, current_page, last_page, total, stats:{ms,bytes} }`.
- **`show`** (`:38-45`) — loads `user` relation, renders `Events/Show` with the full event
  (including raw `payload`).
- **`loadListing`** (`:50-66`) — the core query:
  ```php
  Event::with('user')
      ->when($request->status, fn ($q, $s) => $q->where('status', $s))
      ->orderByDesc('created_time')
      ->paginate(50)
      ->withQueryString();
  ```
  - **Only `status` is filtered.** `from` (date) and any location filter are **NOT applied** —
    a gap vs. the coding-test "filter by date and location" requirement.
  - `stats` instrumentation: measures query+serialize wall time (`ms`) and the byte size of the
    serialized page items (`bytes`). This is a deliberate perf-visibility harness for the dataset.
  - `orderByDesc('created_time')` over 1.25M rows with **no index on `created_time`** → expensive
    sort on every page load. Eager-loading `user` is fine (avoids N+1).

### Vue pages
- **`Events/Index.vue`** (191 lines) — a **debug/admin table**, not the final UI:
  - Infinite scroll via `IntersectionObserver` + `fetch('/events/data')` (`:46-113`).
  - Shows ID, type, status badge, user, raw `created_time` integer, View link.
  - Displays the load-stats footer ("Loaded X KB in Ys").
  - **⚠️ PLANTED BUG (`:148`):** `<Button @click.prevent="aplyFilters">` calls **`aplyFilters`**
    (typo) but the defined function is **`applyFilters`** (`:74`). The Filter button does nothing.
    (Infinite scroll still re-reads `form.status`/`form.from` on the next page fetch, so status
    filtering "works" incidentally as you scroll, but the explicit Filter button is broken and the
    list is never reset.)
  - **`from`** is sent as a query param (`:54`) but the backend ignores it → date filter is a no-op.
- **`Events/Show.vue`** (31 lines) — dumps `JSON.stringify(payload)` in a `<pre>`. No real UI.
- **`Events/VisualOne.vue`** — **EMPTY STUB**: just a `<Head>` + centered "Events Visual 1" heading.
- **`Events/VisualTwo.vue`** — **EMPTY STUB**: same, "Events Visual 2". **These two are the build target.**

Sidebar (`resources/js/components/AppSidebar.vue`) already links Dashboard, Events,
**Events Visual 1**, **Events Visual 2**.

---

## 7. The Seeded Dataset

### `EventSeeder` (`database/seeders/EventSeeder.php`)
- **Scale:** `SEED_ROWS` env, **default `1_250_000` events** (`:67`); **`NUM_USERS = 3000`**
  (`:17`). `DatabaseSeeder` also creates one `test@example.com` user (`:18-21`).
  Comment estimates **≈2.5 GB on disk, ~3s first listing load** (`DatabaseSeeder.php:23-24`).
- **Insert strategy:** chunked bulk inserts of `CHUNK = 4000` (`:19`) wrapped in transactions
  (`:155-157`); `disableQueryLog()` (`:91`).
- **Payload:** a single **template string** (`payloadTemplate`, `:207-245`) built once with
  `{{TOKEN}}` placeholders, then `strtr`-substituted per row (`:128-139`) — cheap. Padded with
  Lorem-ipsum `notes` to hit **`PAYLOAD_AVG_BYTES = 1500`** (`:15`). Payload JSON shape:
  `name, category, description, organizer{name,verified}, venue{name,capacity},
  location{lat,lng}, schedule{starts_at,ends_at}, pricing{currency,min_price}, tags[], notes`.
  - Note: in the seeder, `venue.capacity`, `location.lat/lng`, `schedule.*`, `pricing.min_price`
    are substituted as **strings** (template tokens), whereas the **factory** stores them as
    native ints/floats. Frontend should not assume numeric types in `payload`.
- **Coordinates:** **`CITY_ANCHORS`** (`:37-63`) — ~78 real city [lat,lng] anchors (US, Canada,
  Mexico, Europe, global hubs). Each row jitters ±0.5° around a random anchor (`:120-122`). So
  events cluster around real cities — good for grouping/geocoding by city.
- **Weighting:** `cumulativeWeights` + `pick` (`:270-294`) give weighted random type
  (`[20,14,22,12,12,8,8,4]`) and status (`[12,70,8,10]` → ~70% published).
- **Times:** `created_time` = random UNIX ts spanning **−1 year … +1 year** from now (`:96-117`);
  `ends_at` = start + 1h…3 days. So roughly half the events are in the future (relevant for
  reminders — only future events can trigger 3-day/24-hour reminders).
- **SQLite seeding PRAGMAs** (`withSeedingPragmas`, `:296-316`): during seeding sets
  `journal_mode=MEMORY`, `synchronous=OFF`, `temp_store=MEMORY`, `cache_size=-64000`, then
  restores `WAL` + `synchronous=NORMAL`. Pure speed optimization for the bulk load.

### `EventFactory` (`database/factories/EventFactory.php`)
- Used by tests (not the mass seed). Produces a single realistic event with `faker` lat/lng,
  `created_time` in ±1 year, and a structured `payload` (numeric venue/pricing values). No
  `description`/`organizer`/`tags`/`notes` keys (lighter than seeder payload).

### Performance considerations at scale
- 1.25M rows × ~1.5 KB payload ≈ 2 GB+. The listing sorts by an **unindexed** `created_time`.
  Selecting `payload` (longText) for every row is heavy; the listing currently avoids reading
  payload in the table but `data` serializes full models. For the visual pages, **avoid
  `SELECT *`** and avoid unbounded `payload` hydration; add indexes (`created_time`, maybe
  generated columns or a denormalized table) and keep pagination/lazy loading.

---

## 8. Auth & Starter-Kit Scaffolding (NOT in coding-test scope)

This is **Laravel Vue Starter Kit boilerplate** — leave it alone unless integrating.
- **Fortify** (`config/fortify.php`): features enabled = registration, resetPasswords,
  emailVerification, twoFactorAuthentication (confirm + confirmPassword), passkeys
  (confirmPassword). `home => /dashboard`, `username => email`, lowercase usernames.
- **Actions** (`app/Actions/Fortify/`): `CreateNewUser` (validates via `ProfileValidationRules`
  + `PasswordValidationRules`, creates user), `ResetUserPassword`.
- **Concerns** (`app/Concerns/`): `ProfileValidationRules` (name/email rules with unique-ignore),
  `PasswordValidationRules`.
- **Settings controllers** (`app/Http/Controllers/Settings/`): `ProfileController`
  (edit/update/destroy; uses `Inertia::flash('toast', ...)` on update — the flash-toast pattern),
  `SecurityController` (password + 2FA + passkeys management).
- **Form Requests** (`app/Http/Requests/Settings/`): `ProfileUpdateRequest`,
  `ProfileDeleteRequest`, `PasswordUpdateRequest`, `TwoFactorAuthenticationRequest`.
- Vue: full `auth/*` page set, `settings/*` pages, passkey + 2FA components/composables.

---

## 9. Frontend Architecture

- **`resources/js/app.ts`** — `createInertiaApp`. **Layout resolver** (`:12-23`):
  `Welcome` → no layout; `auth/*` → `AuthLayout`; `settings/*` → `[AppLayout, SettingsLayout]`;
  **everything else (incl. `Events/*`) → `AppLayout`**. So the visual pages automatically get the
  sidebar shell. Progress bar color `#4B5563`. Calls `initializeTheme()` + `initializeFlashToast()`.
- **Layouts** (`resources/js/layouts/`): `AppLayout` → `app/AppSidebarLayout.vue`
  (sidebar shell with breadcrumbs); also `app/AppHeaderLayout.vue`; `AuthLayout` →
  `auth/Auth{Card,Simple,Split}Layout.vue`; `settings/Layout.vue`.
- **Components** (`resources/js/components/`): app chrome (`AppHeader`, `AppSidebar`, `NavMain`,
  `NavUser`, `Breadcrumbs`, …), passkey/2FA components, `PlaceholderPattern`.
- **UI library** (`resources/js/components/ui/`) — **shadcn-vue / reka-ui** style, ~40 primitives
  incl. `button`, `card`, `badge`, `dialog`, `sheet`, `select`, `dropdown-menu`, `input`,
  `checkbox`, `label`, `tooltip`, `skeleton`, `spinner`, `sonner` (toast), `avatar`, `sidebar`,
  `navigation-menu`, `collapsible`, `input-otp`, `separator`, `breadcrumb`, `alert`.
  These are ready building blocks for the visual pages. `components.json` configures shadcn-vue.
- **Composables** (`resources/js/composables/`): `useAppearance` (theme), `useCurrentUrl`,
  `useInitials`, `useTwoFactorAuth`.
- **`lib/`**: `flashToast.ts` — listens to Inertia `flash` event and routes
  `flash.toast.{type,message}` to `vue-sonner` (`flashToast.ts:5-16`). `utils.ts` — `cn()` helper.
- **Types** (`resources/js/types/`): `index.ts`, `auth.ts`, `navigation.ts`, `ui.ts`
  (incl. `FlashToast`), `global.d.ts`, `vue-shims.d.ts`.
- **Wayfinder** generates typed route helpers (e.g. `dashboard()` used in sidebar) at Vite build.

---

## 10. Tests

12 test files (`tests/`), Pest-based. **`tests/Pest.php:18` has `RefreshDatabase` commented out
at the suite level** — each feature test file opts in individually (e.g. `EventListingTest`
and the auth tests declare `uses(RefreshDatabase::class)`). With `phpunit.xml` pointing DB at
`:memory:`, tests never touch the seeded file DB.

- **`tests/Feature/EventListingTest.php`** (the relevant one):
  1. `events.index` renders the `Events/Index` shell unauthenticated, with `statuses` (4) and
     `filters.from = '2023-01-01'`.
  2. `events.data` returns the JSON structure incl. `stats.{ms,bytes}`, correct
     `total`, and event fields (`type`, `created_time`, `latitude`, `user.name`).
  3. `events.data?status=cancelled` filters by status.
  4. `events.show` renders `Events/Show` with the event payload.
  5. **The two visual pages + dashboard render OK** (`events.visual1/2`, `dashboard`) — so any
     rebuild of `VisualOne`/`VisualTwo` must keep these routes returning 200.
- **Auth tests** (`tests/Feature/Auth/*`): authentication, email verification, password
  confirmation, password reset, registration, two-factor challenge, verification notification.
- **Settings tests**: `ProfileUpdateTest`, `SecurityTest`.
- **Examples**: `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`.
- `tests/TestCase.php` — base with `skipUnlessFortifyHas()` helper.

**There are currently no tests for attendees, emails, reminders, images, or geocoding** — those
must be added with the feature.

---

## 11. Config & Environment

- **`.env.example`**: `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost`,
  `APP_LOCALE=en`. `DB_CONNECTION=sqlite`. `SESSION_DRIVER=database`, `CACHE_STORE=database`,
  `QUEUE_CONNECTION=database`, `BROADCAST_CONNECTION=log`, `FILESYSTEM_DISK=local`.
  **`MAIL_MAILER=log`** → **emails are written to the log, not actually sent** (`config/mail.php:17`
  default also `log`). Confirmation/reminder emails will appear in `storage/logs` via `pail`.
- **`config/database.php`** — default `sqlite`, file at `database_path('database.sqlite')`.
- **`config/queue.php`** — default `database`; `jobs`/`failed_jobs` tables exist. Reminder emails
  should be queued and processed by `queue:listen` (already in `composer dev`).
- **`config/mail.php`** — default mailer `log`.
- **`config/filesystems.php`** — `local` disk root = `storage/app/private`; **`public` disk** root
  = `storage/app/public`, URL `${APP_URL}/storage`; `links` maps `public/storage` →
  `storage/app/public` (run `artisan storage:link`). **This is the natural place for locally
  served event images.**
- **`config/inertia.php`** — SSR enabled (`ssr.enabled = true`), testing assertions configured.
- **`config/fortify.php`** — see §8. **`config/services.php`** — currently no geocoding service
  keys; would need adding if using an external reverse-geocoder.

---

## 12. Gap Analysis — Requirement → Current State → To Build

| # | Requirement | Current state | To build |
|---|---|---|---|
| 1 | Two **distinct** visual pages | `VisualOne.vue` / `VisualTwo.vue` are empty stubs; routes + sidebar links exist | Build two genuinely different layouts (e.g. card grid + map, or calendar + timeline) consuming events with title/description/location/datetime/image |
| 2 | **Title + description** per event | In `payload.name` / `payload.description` (seeder) — but **factory payload has no description** | Surface from payload; ensure a fallback; maybe expose via a clean API/resource |
| 3 | **Images, 2+ per event, local** | **None.** `public`/`local` disks + `storage:link` available | Add image storage (new `event_images` table or `payload`/column), seed/attach ≥2 placeholder images per event served from `storage/app/public`; an Inertia/API endpoint to deliver them |
| 4 | **Address from lat/lng** | Only `latitude`/`longitude` (+ payload `location`) | Reverse-geocode. Options: precompute city from nearest `CITY_ANCHORS` (cheap, offline, fits the seeded clustering), or call an external geocoder (needs `config/services.php` + caching). At 1.25M rows, precompute/denormalize — do not geocode per request |
| 5 | **Date/time + timezones** | `created_time` UNIX ts (event start); CarbonImmutable globally | Format per event; decide timezone strategy (store UTC, display in viewer/event-local tz). No JS date lib installed yet |
| 6 | **Filter by date AND location** | Only `status` filtered server-side; `from` plumbed but **ignored**; Filter button **broken (typo)** | Add date-range + location filters in `loadListing`; fix the `aplyFilters` typo; reset list on filter; index `created_time` |
| 7 | **Tailwind** | Tailwind v4 fully wired | Use it (already required) |
| 8 | **Animations** | `tw-animate-css` installed; reka-ui transitions | Add tasteful animations (list/card transitions, map/calendar interactions) |
| 9 | **Attendee registration** | **No attendees table/relation/model** | New `attendees`/`event_user` table + model + relation on `Event`; registration endpoint + UI; an attendee list |
| 10 | **Confirmation email on register** | Mail infra present, `MAIL_MAILER=log`; `Notifiable` user | Mailable/Notification sent on registration (queued); visible in logs |
| 11 | **Reminder emails at 3 days + 24 hours** | Queue infra (`jobs`/`failed_jobs`, `queue:listen` in dev) but **no scheduler/commands/console schedule** | Scheduled command (in `routes/console.php` or `app/Console`) that scans upcoming events and dispatches reminder jobs at the 3-day and 24-hour thresholds to registered attendees; requires the scheduler (`schedule:work`) and queue worker running |

### Hardest parts
1. **Scale/performance (1.25M rows, unindexed `created_time`, 2 GB DB)** — every listing,
   filter, geocode, and reminder scan must be index-backed and avoid full payload hydration.
2. **JSON `payload` querying** — filtering/sorting/searching by name, date, location means JSON
   extraction (slow on SQLite at scale) or denormalization into real, indexed columns.
3. **Image storage end to end** — schema, ≥2 per event, local serving, seeding placeholders for
   a huge dataset without bloating it further (reuse a few placeholder files + a mapping).
4. **Reverse geocoding at scale** — can't geocode 1.25M rows on the fly; precompute (nearest
   city anchor is the pragmatic offline approach) or cache aggressively.
5. **Scheduled reminder jobs + queue** — correct windowing so each attendee gets exactly one
   3-day and one 24-hour email (idempotency), only for future events, dispatched via the
   database queue and a running scheduler/worker (not configured beyond dev's `queue:listen`).

---

## 13. Risks / Notable Observations

- **Planted bug (high confidence):** `Events/Index.vue:148` `@click.prevent="aplyFilters"` —
  misspelled; function is `applyFilters` (`:74`). The Filter button is a no-op. Candidate is
  expected to find/fix (or the visual pages replace this debug UI entirely).
- **Date filter not implemented server-side:** `from` is in props/query but `loadListing`
  (`EventController.php:54-58`) never applies it; no location filter at all. Directly conflicts
  with requirement #6.
- **No `created_time` index** → expensive `orderByDesc('created_time')` over 1.25M rows. Add an
  index (and indexes for any new filter columns) before building the visual pages.
- **`MAIL_MAILER=log`** — confirmation/reminder emails are NOT actually sent in local; they land
  in `storage/logs` (viewable via `pail`). Acceptable for the test; verify via log/`array` in tests.
- **Queue worker required for reminders** — `composer dev` runs `queue:listen`, but there is **no
  scheduler** (`schedule:work` / cron) configured; the reminder pipeline needs one.
- **Payload type inconsistency** — seeder stores some payload values as strings; factory as
  numbers. Don't assume numeric types when reading `payload` in the frontend/backend.
- **Auth not enforced on event routes** — `events.*` and the visual pages are public. Attendee
  registration likely needs an authenticated user (or capture an email); decide the auth model.
- **`tests/Pest.php` `RefreshDatabase` is commented out** at suite level — new feature tests must
  opt in per-file (as `EventListingTest` does) to avoid touching real data; CI/phpunit use
  `:memory:` so this is safe in practice.
- **Single baseline commit** (`4e63291 chore: baseline import…`) — no prior history to mine.
