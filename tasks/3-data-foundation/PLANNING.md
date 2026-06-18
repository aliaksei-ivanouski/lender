# Wave 0 Data Foundation — Execution Plan

**Task:** TASK-3
**Date:** 2026-06-18
**Source inputs:** ARCHITECTURE.md (TASK-3), BUSINESS_ANALYSIS.md (TASK-2)
**Status:** FINAL — ready for engineer agents

---

## Dependency Graph

```
[T0A-1] CityAnchor.php  ──────────────────────┐
[T0A-2] Migrations       ───────────────────── ├──► [T0B-1] GeocodingService
[T0A-3] Typo fix         (independent leaf)    └──► [T0B-2] TimezoneService
                                                     [T0B-3] EventImage model+factory+seeder wiring
                                                          │
                                                          ▼
                                               [T0C-1] GeocodeEventCities cmd (needs GeocodingService)
                                               [T0C-2] EventImageSeeder      (needs EventImage model)
                                               [T0C-3] TS types              (needs TimezoneService shape)
                                                          │
                                                          ▼
                                               [T0D-1] Pest tests (needs all above)
```

Critical shared-foundation rule: `CityAnchor.php` (T0A-1) MUST be merged before T0B-1 and T0B-2 start. `Event.php` and `EventFactory.php` are owned exclusively by T0B-3 — no other Wave-0 task may touch them.

---

## Wave Plan

### Wave 0-A — Parallel foundation (no inter-dependencies within wave)

All three tasks are fully parallel-safe; they touch disjoint files.

| Task ID | Story(s) | Agent | Description | Files Owned (exact paths) | Acceptance Criteria | Verification Command |
|---------|----------|-------|-------------|--------------------------|---------------------|----------------------|
| T0A-1 | US-102, US-106 | engineer-backend-middle | Create `CityAnchor` PHP class with all 78 anchor definitions, `all()`, `timezoneMap()`, `cityNames()` static methods | `app/Support/CityAnchor.php` | AC-106-1, AC-106-2, AC-106-3 | `composer types:check` (PHPStan passes); count of entries = 78; `CityAnchor::timezoneMap()` returns non-null for every city in `cityNames()` |
| T0A-2 | US-101, US-102, US-103 | engineer-backend-junior | Create two migrations in timestamp order: (1) `add_location_city_and_indexes_to_events_table` with `location_city` nullable varchar(100), index on `location_city`, index on `created_time`, composite index on `(status, created_time)`; (2) `create_event_images_table` with bigint PK, `foreignUuid('event_id')` cascadeOnDelete, `path` varchar(255), `sort_order` unsignedInteger default 0, `alt` nullable varchar(255), timestamps, composite index on `(event_id, sort_order)` | `database/migrations/2026_06_18_000001_add_location_city_and_indexes_to_events_table.php`, `database/migrations/2026_06_18_000002_create_event_images_table.php` | AC-101-1, AC-102-1, AC-103-1, AC-103-2 | `php artisan migrate:fresh --env=testing` exits 0; `composer test` green |
| T0A-3 | US-105 | engineer-web-junior | Fix single-character typo: `"aplyFilters"` -> `"applyFilters"` at line 148 of `Events/Index.vue` | `resources/js/pages/Events/Index.vue` | AC-105-1 | `npm run lint` passes; `npm run types:check` passes; grep confirms zero occurrences of `aplyFilters` in the file |

**Seniority notes:**
- T0A-1: middle — requires domain judgment (78 anchors transcribed from EventSeeder, IANA tz assignment, PHP readonly struct pattern)
- T0A-2: junior — mechanical Blueprint DDL; well-specified schema
- T0A-3: junior — 1-character fix, zero logic

---

### Wave 0-B — Services + Model wiring (depends on Wave 0-A completion)

T0B-1 and T0B-2 depend on T0A-1. T0B-3 depends on T0A-2. All three are parallel-safe with each other (disjoint files).

| Task ID | Story(s) | Agent | Description | Files Owned (exact paths) | Acceptance Criteria | Verification Command |
|---------|----------|-------|-------------|--------------------------|---------------------|----------------------|
| T0B-1 | US-102 | engineer-backend-middle | Create `GeocodingService` (Euclidean nearest-anchor via `CityAnchor::all()`; `nearestAnchor(float, float): CityAnchor`; `cityName(float, float): string`); register as singleton in `AppServiceProvider` | `app/Services/GeocodingService.php`, `app/Providers/AppServiceProvider.php` | AC-102-2, AC-102-3 | `composer types:check`; manual smoke via tinker returns `'New York'` for (40.7, -74.0) |
| T0B-2 | US-106, US-401 | engineer-backend-middle | Create `TimezoneService` (`ianaTimezone`, `formatEventTime`, `localDateToUtcRange` per ARCHITECTURE §5.2); add `date-fns` + `date-fns-tz` to `package.json` | `app/Services/TimezoneService.php`, `package.json` | AC-106-1, AC-106-2, AC-401-1, AC-401-2, AC-401-3, AC-401-4 | `composer types:check`; `npm install` exits 0; `npm run types:check` passes |
| T0B-3 | US-101, US-102 | engineer-backend-middle | (a) Create `app/Models/EventImage.php` (`$guarded=[]`, `$appends=['url']`, `event()` BelongsTo, `getUrlAttribute()` via `Storage::disk('public')->url($this->path)`). (b) Create `database/factories/EventImageFactory.php`. (c) Add to `app/Models/Event.php`: `images()` HasMany ordered by sort_order; `registrations()` HasMany stub with `// TODO Wave-3` comment; `'created_time' => 'integer'` cast. (d) Add `withCity(string $city='New York')` and `future()` states to `database/factories/EventFactory.php`. (e) Update `database/seeders/DatabaseSeeder.php` to call `EventImageSeeder::class` after `EventSeeder::class`. (f) Add 8 placeholder JPEGs to `database/seeders/images/placeholder-01.jpg` … `placeholder-08.jpg` (royalty-free 640x480, <=80 KB each). | `app/Models/EventImage.php`, `database/factories/EventImageFactory.php`, `app/Models/Event.php`, `database/factories/EventFactory.php`, `database/seeders/DatabaseSeeder.php`, `database/seeders/images/placeholder-01.jpg` … `placeholder-08.jpg` | AC-101-1, AC-101-3, AC-101-4, AC-101-5, AC-101-6 | `composer test` green; `Event::factory()->create()->images` returns collection |

**Seniority notes:**
- T0B-1: middle — algorithm correctness matters; singleton registration
- T0B-2: middle — Carbon/CarbonImmutable idioms, UTC range math, correct format strings
- T0B-3: middle — multi-file coordination, cast/relation correctness; owns the highest-risk shared files

---

### Wave 0-C — Commands, Seeder, TypeScript (depends on Wave 0-B completion)

All three tasks are parallel-safe within this wave.

| Task ID | Story(s) | Agent | Description | Files Owned (exact paths) | Acceptance Criteria | Verification Command |
|---------|----------|-------|-------------|--------------------------|---------------------|----------------------|
| T0C-1 | US-102 | engineer-backend-middle | Create `GeocodeEventCities` Artisan command (`events:geocode-cities {--chunk=4000}`): idempotent `WHERE location_city IS NULL`, chunked reads, group-by-city UPDATE batching per ARCHITECTURE §8 | `app/Console/Commands/GeocodeEventCities.php` | AC-102-4, AC-102-2 | `php artisan events:geocode-cities --help` exits 0; 10-event in-memory test sets all location_city |
| T0C-2 | US-101 | engineer-backend-middle | Create `EventImageSeeder` (`Event::select('id')->chunk(4000, ...)`, deterministic `crc32($id) & 0x7FFFFFFF % 8` placeholder selection, `DB::table('event_images')->insert($batch)` per chunk, seeding PRAGMAs duplicated inline — do NOT touch EventSeeder; `Storage::disk('public')->makeDirectory('event-images')` + copy source JPEGs before insert) | `database/seeders/EventImageSeeder.php` | AC-101-3, AC-101-4, AC-101-6 | seeding 10 events produces 20 `event_images` rows; `composer test` green |
| T0C-3 | US-101, US-401 | engineer-web-junior | Add `EventImage` TS interface and extend `Event` interface in `resources/js/types/index.ts` per ARCHITECTURE §10 | `resources/js/types/index.ts` | AC-401-1, AC-401-2 | `npm run types:check` passes |

**Seniority notes:**
- T0C-1: middle — chunked UPDATE grouping strategy, idempotency guard
- T0C-2: middle — PRAGMA handling, crc32 determinism, performance
- T0C-3: junior — type declaration only

---

### Wave 0-D — Tests (depends on Wave 0-C completion)

| Task ID | Story(s) | Agent | Description | Files Owned (exact paths) | Acceptance Criteria | Verification Command |
|---------|----------|-------|-------------|--------------------------|---------------------|----------------------|
| T0D-1 | US-101, US-102, US-103, US-106, US-401 | engineer-backend-middle | Pest feature tests: (1) `tests/Feature/GeocodingTest.php` — nearest-anchor correctness for >=5 known coords, no HTTP, all 78 cities map to non-null IANA tz, command idempotency. (2) `tests/Feature/ImageStorageTest.php` — factory event has >=2 images, relation ordered by sort_order, url accessor returns string, seeder produces 2 rows/event. (3) Extend `tests/Feature/EventListingTest.php` — assert PRAGMA index_list(events) contains created_time and location_city indexes. All use RefreshDatabase + in-memory sqlite; no 1.25M seed dependency. | `tests/Feature/GeocodingTest.php`, `tests/Feature/ImageStorageTest.php`, `tests/Feature/EventListingTest.php` | ARCHITECTURE §13 ACs | `composer test` — all pass, zero existing tests broken |

---

## File Ownership Matrix

| File | Owning Task | Wave | Conflict Notes |
|------|-------------|------|----------------|
| `app/Support/CityAnchor.php` | T0A-1 | 0-A | New file |
| `database/migrations/2026_06_18_000001_*.php` | T0A-2 | 0-A | New file |
| `database/migrations/2026_06_18_000002_*.php` | T0A-2 | 0-A | New file |
| `resources/js/pages/Events/Index.vue` | T0A-3 | 0-A | Single-line change |
| `app/Services/GeocodingService.php` | T0B-1 | 0-B | New file |
| `app/Providers/AppServiceProvider.php` | T0B-1 | 0-B | Only T0B-1 in Wave 0 |
| `app/Services/TimezoneService.php` | T0B-2 | 0-B | New file |
| `package.json` | T0B-2 | 0-B | Only T0B-2 in Wave 0 |
| `app/Models/EventImage.php` | T0B-3 | 0-B | New file |
| `database/factories/EventImageFactory.php` | T0B-3 | 0-B | New file |
| `app/Models/Event.php` | T0B-3 exclusively | 0-B | SHARED US-101/US-102 — serialized into T0B-3 |
| `database/factories/EventFactory.php` | T0B-3 exclusively | 0-B | SHARED — serialized into T0B-3 |
| `database/seeders/DatabaseSeeder.php` | T0B-3 exclusively | 0-B | SHARED — single writer |
| `database/seeders/images/placeholder-01…08.jpg` | T0B-3 | 0-B | New files |
| `app/Console/Commands/GeocodeEventCities.php` | T0C-1 | 0-C | New file |
| `database/seeders/EventImageSeeder.php` | T0C-2 | 0-C | New file |
| `resources/js/types/index.ts` | T0C-3 | 0-C | Additions only |
| `tests/Feature/GeocodingTest.php` | T0D-1 | 0-D | New file |
| `tests/Feature/ImageStorageTest.php` | T0D-1 | 0-D | New file |
| `tests/Feature/EventListingTest.php` | T0D-1 | 0-D | Extension; single test agent |

No two parallel tasks within the same wave write to the same file.

---

## Image-placeholder gitignore Resolution

`storage/app/public/.gitignore` contains `*` + `!.gitignore`, blanket-excluding everything there. Committing placeholders to `storage/app/public/event-images/` would silently fail to track.

**Chosen resolution (T0B-3 + T0C-2):** Keep placeholder source images in the tracked dir `database/seeders/images/` (committed to git). `EventImageSeeder` copies them to `storage/app/public/event-images/` at seed time (`Storage::disk('public')->makeDirectory('event-images')` then copy). The `path` stored in `event_images` is `event-images/placeholder-NN.jpg` (relative to the public disk); after `artisan storage:link` they serve at `/storage/event-images/placeholder-NN.jpg`. Do NOT modify `storage/app/public/.gitignore`.

---

## Risks and Sequencing Notes

| ID | Risk | Mitigation |
|----|------|-----------|
| R-001 | `CityAnchor::all()` count != `EventSeeder::CITY_ANCHORS` | T0A-1 counts entries in EventSeeder.php first; GeocodingTest asserts count == 78 |
| R-003 | `crc32()` negative on 32-bit PHP | Use `crc32($id) & 0x7FFFFFFF` before modulo (T0C-2) |
| R-004 | EventSeeder `withSeedingPragmas` is private | Duplicate PRAGMA block inline in EventImageSeeder; do NOT touch EventSeeder |
| R-006 | `storage/app/public/event-images/` absent at clone | EventImageSeeder calls `makeDirectory` before copy (T0C-2) |
| R-007 | PHPStan flags `CityAnchor::all()` return type | Add `/** @return list<self> */` docblock; run `composer types:check` (T0A-1) |
| R-008 | `Event.php` edits land before Wave-3 EventRegistration | T0B-3 adds `registrations()` stub with `// TODO Wave-3` |

**Serialization:** 0-A → 0-B → 0-C → 0-D. Within each wave, tasks are parallel-safe.

**Out of scope for Wave 0:** `EventController.php` (Wave 1 / US-104). No Wave-0 agent touches it.

**Wave-0 QA gate:** after all waves, run `composer test` and `npm run types:check` before Wave 1.
