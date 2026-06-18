# Frontend Architecture — Event Visual Pages (TASK-5)

**Version:** 1.0  
**Date:** 2026-06-18  
**Status:** FINAL — ready for implementation

---

## 1. Decisions Summary

| # | Decision | Rationale |
|---|---|---|
| D-FE-01 | Extract shared fetch/filter/scroll logic into `useEventsData` composable | Avoids duplicating ~80 lines across Index, VisualOne, VisualTwo |
| D-FE-02 | Convert visual route stubs to controller routes; add `visualOne()` / `visualTwo()` methods to `EventController` | The pages need `filters`, `statuses`, `cities` props identical to `index()`. Route::inertia cannot inject props. Controller change is minimal (~12 lines). |
| D-FE-03 | Leaflet + leaflet.markercluster loaded via dynamic import inside `onMounted` | Avoids `window is not defined` in Vite SSR path; type-safe with `@types/leaflet` + `@types/leaflet.markercluster` |
| D-FE-04 | Map renders only the currently-fetched page set (≤50 events per page) | Scale guard: 1.25M markers would crash the browser. Each filter change clears and replots the returned page set. |
| D-FE-05 | Timezone display is fully server-rendered (`starts_at_local`, `starts_at_date`, `tz_label` from `EventResource`) | No client-side tz lib needed for display. `date-fns`/`luxon` is NOT required by this feature (AC-401-4 may affect other features; out of scope here). |
| D-FE-06 | VisualOne animations: `<TransitionGroup name="card">` with `prefers-reduced-motion` media query guard in CSS | Tasteful, accessible, zero JS overhead |
| D-FE-07 | VisualTwo side panel: included as an optional collapsible list synced with map viewport events | Adds discoverability without hiding the map |
| D-FE-08 | Filter debounce: 400ms on text/date inputs; immediate on select change | Prevents excessive API calls during typing |

---

## 2. Data Flow

### 2.1 Route / Controller Changes (Required)

Replace the two `Route::inertia` stubs in `routes/web.php`:

```
// REMOVE:
Route::inertia('events-visual-1', 'Events/VisualOne')->name('events.visual1');
Route::inertia('events-visual-2', 'Events/VisualTwo')->name('events.visual2');

// ADD:
Route::get('events-visual-1', [EventController::class, 'visualOne'])->name('events.visual1');
Route::get('events-visual-2', [EventController::class, 'visualTwo'])->name('events.visual2');
```

Add to `EventController`:

```php
public function visualOne(Request $request): Response
{
    return Inertia::render('Events/VisualOne', $this->sharedListingProps($request));
}

public function visualTwo(Request $request): Response
{
    return Inertia::render('Events/VisualTwo', $this->sharedListingProps($request));
}

private function sharedListingProps(Request $request): array
{
    return [
        'filters' => [
            'status'        => $request->status,
            'from'          => $request->input('from', '2023-01-01'),
            'to'            => $request->input('to'),
            'location_city' => $request->input('location_city'),
        ],
        'statuses' => ['draft', 'published', 'cancelled', 'sold_out'],
        'cities'   => City::orderBy('name')->pluck('name'),
    ];
}
```

The `index()` method also calls this helper (replace its inline array).

### 2.2 Client-Side Data Flow

```
Inertia props (filters, statuses, cities)
        |
        v
useEventsData(initialFilters)
  - reactive form (status, from, to, location_city)
  - ref: rows[], page, lastPage, total, loading, hasLoadedOnce
  - loadMore()  → GET /events/data?page=N&...filters
  - applyFilters() → reset rows/page, call loadMore()
  - infinite-scroll via IntersectionObserver on a sentinel ref
        |
        v
VisualOne.vue          VisualTwo.vue
  <FilterBar />          <FilterBar />
  <EventCard /> ×N       <LeafletMap />  (markers from rows)
  <TransitionGroup>      <EventSidePanel /> (optional, synced)
  <EventCardSkeleton />
```

---

## 3. Shared Composable: `useEventsData`

**File:** `resources/js/composables/useEventsData.ts`

**Signature:**
```ts
export function useEventsData(initialFilters: EventFilters): {
  form: EventFilters                      // reactive, v-model-able
  rows: Ref<EventListItem[]>
  loading: Ref<boolean>
  hasLoadedOnce: Ref<boolean>
  total: Ref<number | null>
  hasMore: ComputedRef<boolean>
  sentinel: Ref<HTMLElement | null>       // attach to IntersectionObserver
  applyFilters(): void
  loadMore(): Promise<void>
  statusVariant(status: string): BadgeVariant
}
```

Extracted verbatim from `Index.vue` logic. `Index.vue` is refactored to consume it. VisualOne and VisualTwo also consume it.

---

## 4. Component Hierarchy

### 4.1 Shared Components (new files)

```
resources/js/components/events/
  FilterBar.vue          — status select + date from/to + city select + Filter button
  EventCard.vue          — cover image, name, description excerpt, city, date/time, status badge
  EventCardSkeleton.vue  — skeleton placeholder (uses <Skeleton> from ui/skeleton)
  EventEmptyState.vue    — "No events match your filters" illustration + message
  EventErrorState.vue    — "Failed to load events" + retry button
```

#### FilterBar.vue props
```ts
defineProps<{
  form: { status: string; from: string; to: string; location_city: string }
  statuses: string[]
  cities: string[]
  loading?: boolean
}>()
defineEmits<{ (e: 'apply'): void }>()
```
- Each field emits `update:form` (v-model compatible) or uses a shared reactive object passed by ref.
- Filter button triggers `apply` emit. Date inputs debounced 400ms.
- All inputs have explicit `<label>` with `for` matching `id`. ARIA: `aria-busy` on container when loading.

#### EventCard.vue props
```ts
defineProps<{
  event: EventListItem
  animate?: boolean   // false if prefers-reduced-motion
}>()
```
- Cover image: `<img :src="event.cover_image_url" :alt="event.name" loading="lazy">` with fallback placeholder div.
- Description: clamped to 3 lines via `line-clamp-3`.
- Date: `{{ event.starts_at_date }} · {{ event.starts_at_local }} {{ event.tz_label }}`.
- Status badge: `<Badge :variant="statusVariant(event.status)">`.
- Entire card is wrapped in `<Link :href="\`/events/${event.id}\`">` for keyboard navigability.

#### EventCardSkeleton.vue
- Renders a fixed-height skeleton card matching EventCard dimensions using the existing `<Skeleton>` component.
- Show 6–12 skeleton cards during initial load.

### 4.2 VisualOne.vue (Card Grid Page)

```
VisualOne.vue
  <Head title="Events — Grid" />
  <AppShell> (or bare layout — match Index.vue pattern)
    <FilterBar @apply="applyFilters" :form :statuses :cities :loading />
    <div aria-live="polite" aria-atomic="false">
      <!-- Loading: skeleton grid -->
      <div v-if="!hasLoadedOnce && loading" class="grid ...">
        <EventCardSkeleton v-for="n in 12" :key="n" />
      </div>
      <!-- Loaded -->
      <TransitionGroup v-else name="card" tag="div" class="grid ...">
        <EventCard v-for="event in rows" :key="event.id" :event :animate />
      </TransitionGroup>
      <!-- Empty -->
      <EventEmptyState v-if="hasLoadedOnce && !loading && rows.length === 0" />
      <!-- Error -->
      <EventErrorState v-if="fetchError" @retry="applyFilters" />
    </div>
    <!-- Sentinel for IntersectionObserver -->
    <div ref="sentinel" aria-hidden="true" />
    <!-- Inline loading indicator -->
    <div v-if="loading && hasLoadedOnce" class="..." aria-label="Loading more events">
      <Spinner />
    </div>
  </AppShell>
```

**Grid breakpoints (Tailwind):**
```
grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4
gap-4 md:gap-6
```

**Transition animation:**
```css
.card-enter-active { transition: opacity 0.3s ease, transform 0.3s ease; }
.card-enter-from   { opacity: 0; transform: translateY(12px); }
@media (prefers-reduced-motion: reduce) {
  .card-enter-active { transition: none; }
  .card-enter-from   { opacity: 0; transform: none; }
}
```
Vue composable exposes `animate` boolean set from `window.matchMedia('(prefers-reduced-motion: reduce)')`.

### 4.3 VisualTwo.vue (Map Page)

```
VisualTwo.vue
  <Head title="Events — Map" />
  <div class="flex h-screen flex-col">
    <FilterBar @apply="onFilterApply" :form :statuses :cities :loading />
    <div class="relative flex flex-1 overflow-hidden">
      <!-- Map container -->
      <div id="leaflet-map" ref="mapContainer" class="flex-1" aria-label="Event map" role="application" />
      <!-- Side panel (collapsible) -->
      <aside class="w-80 overflow-y-auto border-l ..." aria-label="Event list">
        <EventCard v-for="event in rows" :key="event.id" :event />
        <EventEmptyState v-if="hasLoadedOnce && !loading && rows.length === 0" />
      </aside>
    </div>
  </div>
```

---

## 5. Leaflet Integration

### 5.1 npm Dependencies to Add

```
leaflet
leaflet.markercluster
@types/leaflet
@types/leaflet.markercluster
```

Install command (npm, matching project's existing tool):
```
npm install leaflet leaflet.markercluster
npm install -D @types/leaflet @types/leaflet.markercluster
```

### 5.2 CSS Import

In `VisualTwo.vue` `<style>` block (or in the Vite entry):
```ts
// In onMounted, after dynamic import:
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet.markercluster/dist/MarkerCluster.Default.css'
```
Because Leaflet CSS references `url()` assets, import via Vite inside the dynamic import block (not top-level) to avoid SSR/window issues.

### 5.3 Lifecycle Pattern

```ts
// VisualTwo.vue <script setup>
import type { Map as LeafletMap, MarkerClusterGroup } from 'leaflet'

const mapContainer = ref<HTMLElement | null>(null)
let map: LeafletMap | null = null
let clusterGroup: MarkerClusterGroup | null = null

onMounted(async () => {
  const L = (await import('leaflet')).default
  await import('leaflet/dist/leaflet.css')
  const { MarkerClusterGroup } = await import('leaflet.markercluster')
  await import('leaflet.markercluster/dist/MarkerCluster.css')
  await import('leaflet.markercluster/dist/MarkerCluster.Default.css')

  map = L.map(mapContainer.value!, { zoomControl: true }).setView([20, 0], 2)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19,
  }).addTo(map)

  clusterGroup = new MarkerClusterGroup()
  map.addLayer(clusterGroup)

  // Initial data load
  await loadMore()
  plotMarkers()
})

onUnmounted(() => {
  map?.remove()
  map = null
  clusterGroup = null
})
```

### 5.4 Marker Management

```ts
function plotMarkers() {
  if (!clusterGroup) return
  clusterGroup.clearLayers()
  for (const event of rows.value) {
    if (event.latitude == null || event.longitude == null) continue
    const marker = L.marker([event.latitude, event.longitude])
    marker.bindPopup(`
      <strong>${event.name}</strong><br>
      ${event.location_city ?? ''}<br>
      ${event.starts_at_date} · ${event.starts_at_local} ${event.tz_label}<br>
      <a href="/events/${event.id}">View event</a>
    `)
    clusterGroup.addLayer(marker)
  }
}
```

`plotMarkers()` is called after each `loadMore()` resolves and after `applyFilters()` resets. Since the map only plots the current page (≤50 events), marker count is always bounded. This is the **scale guard** — documented in a code comment.

### 5.5 Filter → Map Refresh

```ts
function onFilterApply() {
  applyFilters()           // resets rows, re-fetches page 1
  // watch(rows) re-plots automatically via a watcher:
}
watch(rows, () => plotMarkers(), { deep: false })
```

---

## 6. Timezone / Date Display

Confirmed: the backend `EventResource` already returns:
- `starts_at_local` — formatted time string, e.g. "8:00 PM"
- `starts_at_date` — formatted date string, e.g. "Tue, Jan 7, 2025"
- `tz_label` — timezone abbreviation, e.g. "CET"
- `ends_at_local` — optional formatted end time

Components render these strings directly. No client-side date library is required for these pages.

---

## 7. States Matrix

| State | VisualOne | VisualTwo |
|---|---|---|
| Initial load (before first response) | 12 × `<EventCardSkeleton>` in grid | Map tiles load; spinner overlay |
| Loading more (infinite scroll) | Spinner below grid | No indicator (map re-plots after fetch) |
| Empty (0 results) | `<EventEmptyState>` centered in grid area | `<EventEmptyState>` in side panel; map has no markers |
| Error (fetch rejected) | `<EventErrorState>` with retry button | `<EventErrorState>` in side panel |
| Loaded | `<TransitionGroup>` cards | Markers in cluster group + side panel list |

---

## 8. Accessibility

- All filter inputs: explicit `<label for="...">` linkage.
- Filter form: `role="search"` on the `<form>` element.
- Card grid container: `aria-live="polite"` so screen readers announce new cards.
- Map container: `role="application"` + `aria-label="Event map"`. Each popup has a readable link.
- Images: `alt` from `event.name`; if `cover_image_url` is null, no `<img>` rendered (no broken alt).
- Status badges: color alone does not convey meaning — badge text is the primary indicator.
- WCAG AA contrast: Tailwind color tokens (card background, muted-foreground, primary) must be audited against AA — flag for engineer.
- Keyboard: all cards are links (full keyboard nav); filter form submits on Enter.
- Reduced motion: CSS `@media (prefers-reduced-motion: reduce)` disables card enter transitions.

---

## 9. Performance Notes

- **Image lazy loading:** `loading="lazy"` on all `<img>` in `EventCard`.
- **Filter debounce:** 400ms via `useEventsData` for date inputs; selects trigger immediately.
- **Map marker count:** bounded to page size (≤50); `clusterGroup.clearLayers()` called before each re-plot. No accumulation across pages.
- **Payload column:** `EventController.loadListing()` already avoids `SELECT *`; confirm `payload` is not selected in the listing query (enforced by NFR-002).
- **Leaflet code-split:** dynamic import ensures ~140 KB Leaflet bundle is not shipped to users of VisualOne or the main Events page.

---

## 10. File List — Ownership Grouping

### Group A — Backend (1 engineer, sequential before frontend)

| File | Action | Notes |
|---|---|---|
| `app/Http/Controllers/EventController.php` | MODIFY | Add `visualOne()`, `visualTwo()`, extract `sharedListingProps()` |
| `routes/web.php` | MODIFY | Replace 2 `Route::inertia` stubs with controller routes |
| `tests/Feature/EventListingTest.php` | MODIFY | Add assertions: visual1/visual2 return HTTP 200 + expected Inertia props (`filters`, `statuses`, `cities`) |

### Group B — Shared Frontend (1 engineer, before C and D)

| File | Action | Notes |
|---|---|---|
| `resources/js/composables/useEventsData.ts` | CREATE | Extract from Index.vue; add `fetchError` ref + debounce |
| `resources/js/components/events/FilterBar.vue` | CREATE | Shared across Index, VisualOne, VisualTwo |
| `resources/js/components/events/EventCard.vue` | CREATE | Shared card component |
| `resources/js/components/events/EventCardSkeleton.vue` | CREATE | Uses existing `<Skeleton>` |
| `resources/js/components/events/EventEmptyState.vue` | CREATE | Empty state |
| `resources/js/components/events/EventErrorState.vue` | CREATE | Error state with retry emit |
| `resources/js/pages/Events/Index.vue` | MODIFY | Refactor to consume `useEventsData` + `FilterBar`; fix `aplyFilters` typo (US-105) |
| `resources/js/types/data.ts` | MODIFY | Add `EventFilters` interface; `BadgeVariant` type alias if needed |

### Group C — VisualOne (parallelizable after B)

| File | Action | Notes |
|---|---|---|
| `resources/js/pages/Events/VisualOne.vue` | MODIFY (full replace) | Card grid page; consumes Group B composable + components |

### Group D — VisualTwo (parallelizable after B; requires Leaflet deps installed)

| File | Action | Notes |
|---|---|---|
| `resources/js/pages/Events/VisualTwo.vue` | MODIFY (full replace) | Map page; dynamic Leaflet import |
| `package.json` | MODIFY | Add leaflet, leaflet.markercluster, @types/leaflet, @types/leaflet.markercluster |

**Shared files (flag for planner):** `EventController.php`, `routes/web.php`, `Index.vue`, `data.ts` — only one engineer touches each at a time. Group B must complete before C and D start.

---

## 11. Test / Verification Approach

| Check | Method |
|---|---|
| Routes return HTTP 200 with Inertia props | Pest: `$this->get('/events-visual-1')->assertInertia(fn($p) => $p->has('filters')->has('statuses')->has('cities'))` |
| Routes return HTTP 200 with Inertia props | Same for `/events-visual-2` |
| Existing test baseline maintained | `composer test` must pass with ≥62 tests (baseline) |
| TypeScript: Leaflet types resolve | `npm run types:check` (vue-tsc --noEmit) — passes when `@types/leaflet` installed |
| Build succeeds with dynamic import | `npm run build` — Vite must chunk Leaflet separately without errors |
| Filter composable unit logic | Pest/Vitest unit test on `applyFilters` reset behavior (optional; low risk) |
| Leaflet DOM interactions | NOT unit-tested (jsdom has no real layout engine) — rely on `npm run build` + manual browser preview |
| WCAG contrast | Manual: Lighthouse accessibility audit on both pages in browser |
| Reduced motion | Manual: enable OS reduced-motion preference; confirm no card animations play |

---

## 12. Open Questions

| # | Question | Impact |
|---|---|---|
| OQ-1 | Should `Index.vue` be refactored to consume `useEventsData` in the same wave, or left as-is? Recommendation: refactor in Group B (same composable extraction pass). | Low — isolated change |
| OQ-2 | Should VisualTwo side panel be collapsible on mobile (hidden by default, toggle button)? | UX decision; default recommendation: hide on `< md` breakpoint, show toggle button |
| OQ-3 | Does the `location_city` column exist and is populated before visual pages are implemented? | Blocks Group C/D — confirm Wave 0 (US-102) is complete |
| OQ-4 | Leaflet default marker icon requires PNG assets from the `leaflet/dist/images/` path. Vite's asset handling may need an explicit `vite.config.ts` alias or `L.Icon.Default.mergeOptions` workaround. Engineer must handle this. | Build/runtime risk — standard Leaflet + Vite known issue |
