# Wave 0 Data Foundation — Execution Plan

**Task:** TASK-3
**Date:** 2026-06-18
**Revision R1:** 2026-06-18 — geocoding design replaced
**Source inputs:** ARCHITECTURE.md (TASK-3 R1), BUSINESS_ANALYSIS.md (TASK-2)
**Status:** REVISED R1 — PR #5 must be revised before merge; do not implement original Wave 0-B geocoding tasks

---

## Revision R1 Note (2026-06-18)

The original Wave 0-B geocoding tasks (T0B-1: `GeocodingService`, T0B-2 partial: `TimezoneService` using `CityAnchor::timezoneMap()`) have been replaced. See ARCHITECTURE.md §R1 for the full rationale.

**Summary of changes to this plan:**

- Wave 0-A gains a new task T0A-0: `cities` migration + `CitySeeder` + `City` model (these are foundations Wave 0-B depends on).
- T0B-1 is replaced: instead of `GeocodingService`, implement `ReverseGeocoder` interface + `DatabaseReverseGeocoder` adapter.
- T0B-2 is revised: `TimezoneService` no longer imports `CityAnchor`; it accepts an IANA timezone string from a resolved `City` model.
- `app/Services/GeocodingService.php` is DELETED (PR #5 must remove it).
- `DatabaseSeeder` call order gains `CitySeeder` before `EventSeeder`.
- Wave 0-C T0C-1 revised: backfill command injects `ReverseGeocoder` interface (not `GeocodingService`).
- Wave 0-D gains `tests/Feature/CitySeederTest.php`.

---

## Dependency Graph

```
[T0A-0] cities migration + City model + CitySeeder  ─────────────────────────────────┐
[T0A-1] CityAnchor.php  ─────────────────────────────────────────────────────────────┤
[T0A-2] events+images migrations                                                      ├──► [T0B-1] ReverseGeocoder interface + DatabaseReverseGeocoder
[T0A-3] Typo fix (independent leaf)                                                   └──► [T0B-2] TimezoneService (revised: accepts tz string, no CityAnchor)
                                                                                           [T0B-3] EventImage model+factory+seeder wiring
                                                                                                │
                                                                                                ▼
                                                                                     [T0C-1] GeocodeEventCities cmd (injects ReverseGeocoder)
                                                                                     [T0C-2] EventImageSeeder      (needs EventImage model)
                                                                                     [T0C-3] TS types              (needs TimezoneService shape)
                                                                                                │
                                                                                                ▼
                                                                                     [T0D-1] Pest tests (needs all above)
```

Critical shared-foundation rule: T0A-0 (`cities` migration + `City` model + `CitySeeder`) MUST be merged before T0B-1 (DatabaseReverseGeocoder queries the `cities` table). `CityAnchor.php` (T0A-1) MUST be merged before T0A-0 (CitySeeder imports it). `Event.php` and `EventFactory.php` are owned exclusively by T0B-3 — no other Wave-0 task may touch them.

---

## Wave Plan

### Wave 0-A — Parallel foundation (no inter-dependencies within wave) [REVISED R1]

T0A-0, T0A-1, T0A-2, T0A-3 are all parallel-safe; they touch disjoint files. T0A-0 depends on T0A-1 for the `CityAnchor` class, so T0A-1 must land first — or T0A-0 and T0A-1 can be batched to the same engineer agent.

| Task ID | Story(s) | Agent | Description | Files Owned (exact paths) | Acceptance Criteria | Verification Command |
|---------|----------|-------|-------------|--------------------------|---------------------|----------------------|
| T0A-0 [NEW R1] | US-102, US-106 | engineer-backend-middle | Create `cities` migration (id, name, region, country, latitude DECIMAL(10,7), longitude DECIMAL(10,7), timezone VARCHAR(64), timestamps; index on (latitude,longitude)); create `app/Models/City.php` Eloquent model (guarded=[], float casts); create `database/seeders/CitySeeder.php` (iterates `CityAnchor::all()`, bulk-inserts into `cities`); update `DatabaseSeeder` to call `CitySeeder::class` before `EventSeeder::class` | `database/migrations/2026_06_18_000000_create_cities_table.php`, `app/Models/City.php`, `database/seeders/CitySeeder.php`, `database/seeders/DatabaseSeeder.php` | `cities` table exists after migrate:fresh; `City::count()` == CityAnchor::all() count after seed; every row has non-null timezone | `php artisan migrate:fresh --seed --env=testing`; `php artisan tinker --execute="echo City::count();"` |
| T0A-1 | US-102, US-106 | engineer-backend-middle | Create `CityAnchor` PHP class with all ~75 anchor definitions and `all()` static method (seed-data only — `timezoneMap()` and `cityNames()` methods are NOT implemented; they are no longer needed) | `app/Support/CityAnchor.php` | CityAnchor::all() count matches EventSeeder::CITY_ANCHORS count; every entry has non-empty ianaTimezone | `composer types:check` (PHPStan passes); count assertion in tinker |
| T0A-2 | US-101, US-102, US-103 | engineer-backend-junior | Create two migrations: (1) `add_location_city_and_indexes_to_events_table` with `location_city` nullable varchar(100), index on `location_city`, index on `created_time`, composite index on `(status, created_time)`; (2) `create_event_images_table` with bigint PK, `foreignUuid('event_id')` cascadeOnDelete, `path` varchar(255), `sort_order` unsignedInteger default 0, `alt` nullable varchar(255), timestamps, composite index on `(event_id, sort_order)`. Timestamps: 000001 and 000002 (AFTER 000000 cities migration) | `database/migrations/2026_06_18_000001_add_location_city_and_indexes_to_events_table.php`, `database/migrations/2026_06_18_000002_create_event_images_table.php` | AC-101-1, AC-102-1, AC-103-1, AC-103-2 | `php artisan migrate:fresh --env=testing` exits 0; `composer test` green |
| T0A-3 | US-105 | engineer-web-junior | Fix single-character typo: `"aplyFilters"` -> `"applyFilters"` at line 148 of `Events/Index.vue` | `resources/js/pages/Events/Index.vue` | AC-105-1 | `npm run lint` passes; grep confirms zero occurrences of `aplyFilters` in the file |

**Seniority notes:**
- T0A-0: middle — migration DDL + model + seeder; must verify CityAnchor count matches
- T0A-1: middle — requires domain judgment (all anchors transcribed from EventSeeder, IANA tz assignment, PHP readonly struct pattern); removed methods vs. original
- T0A-2: junior — mechanical Blueprint DDL; well-specified schema
- T0A-3: junior — 1-character fix, zero logic

---

### Wave 0-B — Services + Model wiring (depends on Wave 0-A completion) [REVISED R1]

T0B-1 depends on T0A-0 (`cities` table + `City` model must exist). T0B-2 depends on T0A-0 (City model). T0B-3 depends on T0A-2 (migrations). All three are parallel-safe with each other (disjoint files).

**PR #5 must be revised before merging.** The original T0B-1 and T0B-2 implementations in PR #5 must be replaced per the R1 design. Do not merge PR #5 as-is.

| Task ID | Story(s) | Agent | Description | Files Owned (exact paths) | Acceptance Criteria | Verification Command |
|---------|----------|-------|-------------|--------------------------|---------------------|----------------------|
| T0B-1 [REVISED R1] | US-102 | engineer-backend-middle | (a) Create `app/Services/Geocoding/ReverseGeocoder.php` interface (`reverse(float $lat, float $lng): ?City`). (b) Create `app/Services/Geocoding/DatabaseReverseGeocoder.php`: constructor-injected `Illuminate\Contracts\Cache\Repository`; `reverse()` checks cache (key `geocoder.rev:{round($lat,2)}.{round($lng,2)}`, TTL 86400); on miss, runs bbox SQL query (WHERE latitude BETWEEN lat-5 AND lat+5 AND longitude BETWEEN lng-5 AND lng+5 ORDER BY ((lat-?)*(lat-?)+(lng-?)*(lng-?)) LIMIT 1); widens Δ to 20.0 then 90.0 if no result; returns `City` model or null. (c) Delete `app/Services/GeocodingService.php`. (d) In `AppServiceProvider`: bind `ReverseGeocoder::class` → `DatabaseReverseGeocoder::class` (transient, not singleton — cache handles deduplication) | `app/Services/Geocoding/ReverseGeocoder.php`, `app/Services/Geocoding/DatabaseReverseGeocoder.php`, `app/Providers/AppServiceProvider.php` (DELETE: `app/Services/GeocodingService.php`) | AC-102-2 (correct city for known coords via DB), AC-102-3 (no HTTP calls); tinker: `app(ReverseGeocoder::class)->reverse(40.71, -74.00)->name` == 'New York' | `composer types:check`; tinker smoke test |
| T0B-2 [REVISED R1] | US-106, US-401 | engineer-backend-middle | Revise `TimezoneService` to remove `CityAnchor` import: `formatEventTime(int $unixTimestamp, string $timezone): array` (accepts IANA tz string directly, not city name); `localDateToUtcRange(string $localDate, string $timezone): array` (same — tz string passed by caller). Caller resolves City via ReverseGeocoder and passes `$city->timezone`. Add `date-fns` + `date-fns-tz` to `package.json`. | `app/Services/TimezoneService.php`, `package.json` | AC-106-1, AC-106-2, AC-401-1, AC-401-2, AC-401-3, AC-401-4 | `composer types:check`; `npm install` exits 0; `npm run types:check` passes |
| T0B-3 | US-101, US-102 | engineer-backend-middle | (a) Create `app/Models/EventImage.php` (`$guarded=[]`, `$appends=['url']`, `event()` BelongsTo, `getUrlAttribute()` via `Storage::disk('public')->url($this->path)`). (b) Create `database/factories/EventImageFactory.php`. (c) Add to `app/Models/Event.php`: `images()` HasMany ordered by sort_order; `registrations()` HasMany stub with `// TODO Wave-3` comment; `'created_time' => 'integer'` cast. (d) Add `withCity(string $city='New York')` and `future()` states to `database/factories/EventFactory.php`. (e) `DatabaseSeeder.php` was updated in T0A-0 (CitySeeder added); T0B-3 adds `EventImageSeeder::class` call after `EventSeeder::class`. (f) Add 8 placeholder JPEGs to `database/seeders/images/placeholder-01.jpg` … `placeholder-08.jpg` (royalty-free 640x480, ≤80 KB each). | `app/Models/EventImage.php`, `database/factories/EventImageFactory.php`, `app/Models/Event.php`, `database/factories/EventFactory.php`, `database/seeders/DatabaseSeeder.php`, `database/seeders/images/placeholder-01.jpg` … `placeholder-08.jpg` | AC-101-1, AC-101-3, AC-101-4, AC-101-5, AC-101-6 | `composer test` green; `Event::factory()->create()->images` returns collection |

**Seniority notes:**
- T0B-1: middle — SQL bbox strategy, cache key design, Δ-widening logic, interface definition, binding
- T0B-2: middle — revised signatures, Carbon/CarbonImmutable idioms, UTC range math
- T0B-3: middle — multi-file coordination, cast/relation correctness; owns the highest-risk shared files

---

### Wave 0-C — Commands, Seeder, TypeScript (depends on Wave 0-B completion) [REVISED R1 for T0C-1]

All three tasks are parallel-safe within this wave.

| Task ID | Story(s) | Agent | Description | Files Owned (exact paths) | Acceptance Criteria | Verification Command |
|---------|----------|-------|-------------|--------------------------|---------------------|----------------------|
| T0C-1 [REVISED R1] | US-102 | engineer-backend-middle | Create `GeocodeEventCities` Artisan command (`events:geocode-cities {--chunk=4000}`): constructor-injects `ReverseGeocoder` interface (NOT `GeocodingService`); idempotent `WHERE location_city IS NULL`; chunked reads; for each event calls `$geocoder->reverse($event->latitude, $event->longitude)`; groups results by city name; issues one `DB::table('events')->whereIn('id', $ids)->update(['location_city' => $city])` per unique city per chunk (~75 statements per 4000-event chunk); reports progress. The Laravel cache warms naturally via DatabaseReverseGeocoder — no special ETL-only loading. See ARCHITECTURE §8 for ETL cache strategy rationale. | `app/Console/Commands/GeocodeEventCities.php` | AC-102-4, AC-102-2; command uses `ReverseGeocoder` interface, not concrete class | `php artisan events:geocode-cities --help` exits 0; 10-event in-memory test sets all location_city |
| T0C-2 | US-101 | engineer-backend-middle | Create `EventImageSeeder` (`Event::select('id')->chunk(4000, ...)`, deterministic `crc32($id) & 0x7FFFFFFF % 8` placeholder selection, `DB::table('event_images')->insert($batch)` per chunk, seeding PRAGMAs duplicated inline — do NOT touch EventSeeder; `Storage::disk('public')->makeDirectory('event-images')` + copy source JPEGs before insert) | `database/seeders/EventImageSeeder.php` | AC-101-3, AC-101-4, AC-101-6 | seeding 10 events produces 20 `event_images` rows; `composer test` green |
| T0C-3 | US-101, US-401 | engineer-web-junior | Add `EventImage` TS interface and extend `Event` interface in `resources/js/types/index.ts` per ARCHITECTURE §10 | `resources/js/types/index.ts` | AC-401-1, AC-401-2 | `npm run types:check` passes |

**Seniority notes:**
- T0C-1: middle — injects ReverseGeocoder interface, chunked UPDATE grouping strategy, idempotency guard
- T0C-2: middle — PRAGMA handling, crc32 determinism, performance
- T0C-3: junior — type declaration only

---

### Wave 0-D — Tests (depends on Wave 0-C completion) [REVISED R1]

| Task ID | Story(s) | Agent | Description | Files Owned (exact paths) | Acceptance Criteria | Verification Command |
|---------|----------|-------|-------------|--------------------------|---------------------|----------------------|
| T0D-1 | US-101, US-102, US-103, US-106, US-401 | engineer-backend-middle | Pest feature tests: (1) `tests/Feature/GeocodingTest.php` [REVISED R1] — `ReverseGeocoder::reverse()` returns correct `City` for ≥5 known anchor coords; no HTTP calls; cache: second call at same rounded coord issues 0 additional DB queries (assert DB query count = 1 for 2 reverse() calls); `events:geocode-cities` idempotency (runs twice, no double-update). (2) `tests/Feature/CitySeederTest.php` [NEW R1] — `cities` table count == `CityAnchor::all()` count; every city row has non-null timezone; `City` model queryable. (3) `tests/Feature/ImageStorageTest.php` — factory event has ≥2 images, relation ordered by sort_order, url accessor returns string. (4) Extend `tests/Feature/EventListingTest.php` — assert PRAGMA index_list(events) contains created_time and location_city indexes. All use RefreshDatabase + in-memory sqlite; no 1.25M seed dependency. | `tests/Feature/GeocodingTest.php`, `tests/Feature/CitySeederTest.php`, `tests/Feature/ImageStorageTest.php`, `tests/Feature/EventListingTest.php` | ARCHITECTURE §13 ACs | `composer test` — all pass, zero existing tests broken |

---

## File Ownership Matrix [REVISED R1]

| File | Owning Task | Wave | Conflict Notes |
|------|-------------|------|----------------|
| `database/migrations/2026_06_18_000000_create_cities_table.php` | T0A-0 | 0-A | New file [R1] |
| `app/Models/City.php` | T0A-0 | 0-A | New file [R1] |
| `database/seeders/CitySeeder.php` | T0A-0 | 0-A | New file [R1] |
| `database/seeders/DatabaseSeeder.php` | T0A-0 then T0B-3 | 0-A/0-B | T0A-0 adds CitySeeder; T0B-3 adds EventImageSeeder — sequential writes, different lines |
| `app/Support/CityAnchor.php` | T0A-1 | 0-A | New file (seed-data only) |
| `database/migrations/2026_06_18_000001_*.php` | T0A-2 | 0-A | New file |
| `database/migrations/2026_06_18_000002_*.php` | T0A-2 | 0-A | New file |
| `resources/js/pages/Events/Index.vue` | T0A-3 | 0-A | Single-line change |
| `app/Services/Geocoding/ReverseGeocoder.php` | T0B-1 | 0-B | New interface [R1] |
| `app/Services/Geocoding/DatabaseReverseGeocoder.php` | T0B-1 | 0-B | New adapter [R1] |
| `app/Providers/AppServiceProvider.php` | T0B-1 | 0-B | Interface→adapter binding [R1] |
| `app/Services/GeocodingService.php` | T0B-1 | 0-B | DELETED in R1 |
| `app/Services/TimezoneService.php` | T0B-2 | 0-B | Revised (no CityAnchor import) [R1] |
| `package.json` | T0B-2 | 0-B | Only T0B-2 in Wave 0 |
| `app/Models/EventImage.php` | T0B-3 | 0-B | New file |
| `database/factories/EventImageFactory.php` | T0B-3 | 0-B | New file |
| `app/Models/Event.php` | T0B-3 exclusively | 0-B | SHARED US-101/US-102 — serialized into T0B-3 |
| `database/factories/EventFactory.php` | T0B-3 exclusively | 0-B | SHARED — serialized into T0B-3 |
| `database/seeders/images/placeholder-01…08.jpg` | T0B-3 | 0-B | New files |
| `app/Console/Commands/GeocodeEventCities.php` | T0C-1 | 0-C | New file (uses ReverseGeocoder) [R1] |
| `database/seeders/EventImageSeeder.php` | T0C-2 | 0-C | New file |
| `resources/js/types/index.ts` | T0C-3 | 0-C | Additions only |
| `tests/Feature/GeocodingTest.php` | T0D-1 | 0-D | New file (tests ReverseGeocoder + cache) [R1] |
| `tests/Feature/CitySeederTest.php` | T0D-1 | 0-D | New file [R1] |
| `tests/Feature/ImageStorageTest.php` | T0D-1 | 0-D | New file |
| `tests/Feature/EventListingTest.php` | T0D-1 | 0-D | Extension; single test agent |

No two parallel tasks within the same wave write to the same file.

**Note on DatabaseSeeder.php sequential writes:** T0A-0 adds `CitySeeder::class` (before EventSeeder) and T0B-3 adds `EventImageSeeder::class` (after EventSeeder). These are in different waves, so there is no concurrent write conflict. T0B-3 must read the file as modified by T0A-0 before editing.

---

## Image-placeholder gitignore Resolution

`storage/app/public/.gitignore` contains `*` + `!.gitignore`, blanket-excluding everything there. Committing placeholders to `storage/app/public/event-images/` would silently fail to track.

**Chosen resolution (T0B-3 + T0C-2):** Keep placeholder source images in the tracked dir `database/seeders/images/` (committed to git). `EventImageSeeder` copies them to `storage/app/public/event-images/` at seed time (`Storage::disk('public')->makeDirectory('event-images')` then copy). The `path` stored in `event_images` is `event-images/placeholder-NN.jpg` (relative to the public disk); after `artisan storage:link` they serve at `/storage/event-images/placeholder-NN.jpg`. Do NOT modify `storage/app/public/.gitignore`.

---

## Risks and Sequencing Notes [REVISED R1]

| ID | Risk | Mitigation |
|----|------|-----------|
| R-001 | `CityAnchor::all()` count != `EventSeeder::CITY_ANCHORS` count | T0A-1 counts entries in EventSeeder.php first; CitySeederTest asserts City::count() == CityAnchor::all() count |
| R-002 [NEW R1] | `DatabaseReverseGeocoder` bbox Δ=5.0° misses an anchor (edge case — event generated near world boundary) | Δ widening (5→20→90) ensures a result is always found; unit test covers known anchor coordinates |
| R-003 | `crc32()` negative on 32-bit PHP | Use `crc32($id) & 0x7FFFFFFF` before modulo (T0C-2) |
| R-004 | EventSeeder `withSeedingPragmas` is private | Duplicate PRAGMA block inline in EventImageSeeder; do NOT touch EventSeeder |
| R-005 [NEW R1] | PR #5 merged before R1 revision applied | PR #5 is explicitly blocked — must not be merged until T0B-1 and T0B-2 revisions are applied |
| R-006 | `storage/app/public/event-images/` absent at clone | EventImageSeeder calls `makeDirectory` before copy (T0C-2) |
| R-007 | PHPStan flags `CityAnchor::all()` return type | Add `/** @return list<self> */` docblock; run `composer types:check` (T0A-1) |
| R-008 | `Event.php` edits land before Wave-3 EventRegistration | T0B-3 adds `registrations()` stub with `// TODO Wave-3` |
| R-009 [NEW R1] | `DatabaseSeeder.php` edited by both T0A-0 and T0B-3 | Sequential waves prevent conflict; T0B-3 reads file after T0A-0 merge |

**Serialization:** 0-A → 0-B → 0-C → 0-D. Within each wave, tasks are parallel-safe.

**Out of scope for Wave 0:** `EventController.php` (Wave 1 / US-104). No Wave-0 agent touches it.

**Wave-0 QA gate:** after all waves, run `composer test` and `npm run types:check` before Wave 1.
