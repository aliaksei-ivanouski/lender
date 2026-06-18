<script setup lang="ts">
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';

// OQ-4 Marker icon Vite fix:
// Static ?url imports are resolved at build time — Vite hashes, copies, and returns a typed string.
// This is the canonical Vite-safe approach: no d.ts shim, no @ts-expect-error, no new URL() in onMounted.
import markerIconUrl from 'leaflet/dist/images/marker-icon.png?url';
import markerIconRetinaUrl from 'leaflet/dist/images/marker-icon-2x.png?url';
import markerShadowUrl from 'leaflet/dist/images/marker-shadow.png?url';

import { Head } from '@inertiajs/vue3';
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import FilterBar from '@/components/events/FilterBar.vue';
import EventCard from '@/components/events/EventCard.vue';
import EventEmptyState from '@/components/events/EventEmptyState.vue';
import EventErrorState from '@/components/events/EventErrorState.vue';
import { useEventsData } from '@/composables/useEventsData';
import type { EventFilters, EventListItem } from '@/types/data';
import type { Map as LeafletMap, MarkerClusterGroup } from 'leaflet';

const props = defineProps<{
    filters: EventFilters;
    statuses: string[];
    cities: string[];
}>();

// MAX_MARKERS caps the map dataset to protect against the 1.25 M-row case.
// markercluster handles ~2000 markers fine; raise only after performance testing.
const MAX_MARKERS = 2000;

const { form, rows, total, loading, error, hasLoadedOnce, setFilters, reloadAll, retry } = useEventsData(props.filters);

// Map refs — non-reactive (raw Leaflet objects must not be made reactive)
const mapContainer = ref<HTMLElement | null>(null);
let map: LeafletMap | null = null;
let clusterGroup: MarkerClusterGroup | null = null;

// Side panel toggle — default true (SSR-safe); corrected to breakpoint on mount
const sidePanelOpen = ref(true);

// Track whether Leaflet has been initialised (guards plotMarkers from being called before init)
const mapReady = ref(false);

/**
 * Scale guard: only plot the currently-loaded page set (≤50 events per fetch).
 * We deliberately do NOT accumulate markers across pages — the map always reflects
 * the rows currently in memory. This prevents the 1.25 M-marker browser crash.
 */
function plotMarkers(L: typeof import('leaflet').default): void {
    if (!clusterGroup || !map) return;

    // Only fitBounds when this is a fresh plot (cluster was empty before this call).
    // Subsequent infinite-scroll appends rebuild the full marker set but should NOT
    // re-zoom/pan the map — the user may have manually navigated to a different area.
    const wasEmpty = clusterGroup.getLayers().length === 0;

    clusterGroup.clearLayers();

    const markersLatLng: [number, number][] = [];

    for (const event of rows.value as EventListItem[]) {
        if (event.latitude == null || event.longitude == null) continue;

        const marker = L.marker([event.latitude, event.longitude]);
        marker.bindPopup(`
            <strong>${escapeHtml(event.name)}</strong><br>
            ${event.location_city ? escapeHtml(event.location_city) + '<br>' : ''}
            ${escapeHtml(event.starts_at_date)} &middot; ${escapeHtml(event.starts_at_local)} ${escapeHtml(event.tz_label)}<br>
            <a href="/events/${encodeURIComponent(event.id)}">View event &rarr;</a>
        `);
        clusterGroup.addLayer(marker);
        markersLatLng.push([event.latitude, event.longitude]);
    }

    if (wasEmpty && markersLatLng.length > 0 && map) {
        map.fitBounds(L.latLngBounds(markersLatLng), { maxZoom: 12, padding: [40, 40] });
    }
}

function escapeHtml(str: string): string {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// Keep a stable reference to L so the watcher can call plotMarkers
let leafletInstance: typeof import('leaflet').default | null = null;

// Watch rows: on filter change the composable resets rows then re-fetches.
// Deep watch is required because the composable mutates the array via push() rather than
// replacing the ref value — a shallow watch never fires on array mutation.
watch(rows, () => {
    if (mapReady.value && leafletInstance) {
        plotMarkers(leafletInstance);
    }
}, { deep: true });

onMounted(async () => {
    // Correct initial panel state to viewport width (SSR defaulted to true above).
    sidePanelOpen.value = window.matchMedia('(min-width: 768px)').matches;

    // Invalidate map size on viewport resize so tiles/markers stay aligned.
    window.addEventListener('resize', onWindowResize);

    // Dynamic import — SSR-safe (window is not available server-side)
    const L = (await import('leaflet')).default;
    await import('leaflet.markercluster');

    leafletInstance = L;

    // Apply the Vite-resolved marker icon URLs (imported as ?url strings at module top).
    L.Icon.Default.mergeOptions({
        iconUrl: markerIconUrl,
        iconRetinaUrl: markerIconRetinaUrl,
        shadowUrl: markerShadowUrl,
    });

    if (!mapContainer.value) return;

    map = L.map(mapContainer.value, { zoomControl: true }).setView([20, 0], 2);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19,
    }).addTo(map);

    // leaflet.markercluster augments the L namespace after the side-effect import above.
    // @types/leaflet.markercluster declares L.MarkerClusterGroup on the leaflet module.
    clusterGroup = new L.MarkerClusterGroup({ showCoverageOnHover: false });
    map.addLayer(clusterGroup);

    mapReady.value = true;

    // Plot any rows that already arrived before init finished (data-first race),
    // then load ALL pages for the initial filter (up to MAX_MARKERS).
    // watch(rows, deep) accumulates markers as pages arrive.
    plotMarkers(leafletInstance);

    await reloadAll(MAX_MARKERS);
});

onUnmounted(() => {
    window.removeEventListener('resize', onWindowResize);
    map?.remove();
    map = null;
    clusterGroup = null;
    leafletInstance = null;
    mapReady.value = false;
});

function onFilterApply(): void {
    // Reset state and load ALL pages for the new filter (up to MAX_MARKERS).
    // watch(rows, deep) will replot markers as each page arrives.
    void reloadAll(MAX_MARKERS);
}

function toggleSidePanel(): void {
    sidePanelOpen.value = !sidePanelOpen.value;
    // Re-measure map after the panel transition so tiles/markers stay aligned.
    void nextTick(() => { map?.invalidateSize(); });
}

function onWindowResize(): void {
    map?.invalidateSize();
}
</script>

<template>
    <Head title="Event Visuals 2" />

    <div class="flex h-screen flex-col overflow-hidden">
        <!-- Page header -->
        <header class="flex flex-col gap-1 border-b px-6 pt-5 pb-4">
            <div class="flex items-baseline gap-3">
                <h1 class="text-2xl font-bold tracking-tight">Event Visuals 2</h1>
                <span
                    v-if="total !== null"
                    class="rounded-full bg-muted px-2.5 py-0.5 text-sm font-medium text-muted-foreground"
                    aria-live="polite"
                    aria-atomic="true"
                >
                    {{ total.toLocaleString() }} events
                </span>
            </div>
            <p class="text-sm text-muted-foreground">
                Explore events on an interactive map. Filter by status, date range, or location.
            </p>
        </header>

        <!-- Filter bar -->
        <div class="border-b px-6 py-3">
            <FilterBar :model-value="form" :statuses="statuses" :cities="cities" :loading="loading" @update:model-value="setFilters" @apply="onFilterApply" />
        </div>

        <!-- Map + side panel layout -->
        <div class="relative flex min-h-0 flex-1 overflow-hidden">

            <!-- Map container -->
            <!-- role="application" signals interactive widget to screen readers -->
            <!-- Side list serves as the keyboard/screen-reader accessible alternative -->
            <div
                ref="mapContainer"
                class="flex-1"
                role="application"
                aria-label="Interactive event map. Use the event list panel for keyboard navigation."
            >
                <!-- Loading overlay (only before first successful fetch) -->
                <div
                    v-if="!hasLoadedOnce && loading"
                    class="absolute inset-0 z-[1000] flex items-center justify-center bg-background/70"
                    aria-live="polite"
                    aria-label="Loading map events"
                >
                    <div class="flex flex-col items-center gap-3">
                        <svg
                            class="size-8 animate-spin text-primary"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                            />
                        </svg>
                        <span class="text-sm font-medium text-muted-foreground">Loading events…</span>
                    </div>
                </div>
            </div>

            <!-- Side panel toggle button (visible on md+; always available on mobile) -->
            <button
                type="button"
                class="absolute top-3 right-3 z-[1000] flex items-center gap-1.5 rounded-md border bg-background px-3 py-1.5 text-sm font-medium shadow-sm hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:hidden"
                :aria-expanded="sidePanelOpen"
                aria-controls="event-side-panel"
                @click="toggleSidePanel"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="size-4"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                {{ sidePanelOpen ? 'Hide list' : 'Show list' }}
            </button>

            <!-- Desktop list toggle -->
            <button
                type="button"
                class="absolute top-3 right-3 z-[1000] hidden items-center gap-1.5 rounded-md border bg-background px-3 py-1.5 text-sm font-medium shadow-sm hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:flex"
                :aria-expanded="sidePanelOpen"
                aria-controls="event-side-panel"
                @click="toggleSidePanel"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="size-4"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" v-if="sidePanelOpen" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" v-else />
                </svg>
                {{ sidePanelOpen ? 'Hide list' : 'Show list' }}
            </button>

            <!--
                Accessible side panel — the primary keyboard/screen-reader interface.
                Leaflet markers are not keyboard-navigable; this list provides full
                accessibility coverage (WCAG 2.1 SC 2.1.1).
                Hidden below md unless toggled open.
            -->
            <!--
                Mobile (< md): absolute drawer overlaying the map from the right.
                  - absolute right-0 top-0 h-full w-full max-w-xs z-[1100]
                  - The map flex-1 keeps full width underneath; the panel slides over it.
                md+: static flex sibling (md:static md:w-80 md:border-l).
                  - Reverts absolute positioning; map flex-1 fills the remainder.
                Hidden (display:none) when sidePanelOpen is false at any breakpoint.
            -->
            <aside
                id="event-side-panel"
                :class="[
                    'flex flex-col overflow-y-auto bg-card',
                    'absolute right-0 top-0 h-full w-full max-w-xs border-l shadow-lg z-[1100]',
                    'md:static md:w-80 md:max-w-none md:shadow-none md:z-auto',
                    sidePanelOpen ? 'flex' : 'hidden',
                ]"
                aria-label="Event list — accessible alternative to the map"
            >
                <div class="sticky top-0 z-10 border-b bg-card px-4 py-3">
                    <h2 class="text-sm font-semibold">Events on map</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Showing {{ rows.length }} of {{ total !== null ? total.toLocaleString() : '…' }} event{{ rows.length === 1 ? '' : 's' }}
                    </p>
                    <!-- Cap notice: shown only when total exceeds MAX_MARKERS and all loaded rows are at the cap -->
                    <p
                        v-if="total !== null && total > MAX_MARKERS && rows.length >= MAX_MARKERS"
                        class="mt-1 text-xs text-amber-600 dark:text-amber-400"
                        role="status"
                        aria-live="polite"
                    >
                        Showing first {{ MAX_MARKERS.toLocaleString() }} of {{ total.toLocaleString() }} — refine filters to narrow results.
                    </p>
                </div>

                <!-- Error state in side panel -->
                <div v-if="error && !hasLoadedOnce" class="p-4">
                    <EventErrorState :message="error" @retry="retry" />
                </div>

                <!-- Inline error on subsequent load failures -->
                <div v-else-if="error && hasLoadedOnce" class="p-4">
                    <EventErrorState :message="error" @retry="retry" />
                </div>

                <!-- Empty state -->
                <div v-else-if="hasLoadedOnce && !loading && rows.length === 0" class="p-4">
                    <EventEmptyState />
                </div>

                <!-- Event cards list -->
                <div
                    v-else-if="rows.length > 0"
                    class="flex flex-col gap-3 p-4"
                    aria-live="polite"
                    aria-atomic="false"
                >
                    <EventCard
                        v-for="event in rows"
                        :key="event.id"
                        :event="event"
                    />
                </div>

                <!-- Loading skeleton while first fetch -->
                <div v-if="!hasLoadedOnce && loading" class="flex flex-col gap-3 p-4" aria-label="Loading events">
                    <div
                        v-for="n in 5"
                        :key="n"
                        class="h-32 animate-pulse rounded-lg bg-muted"
                        aria-hidden="true"
                    />
                </div>
            </aside>
        </div>
    </div>
</template>

<style scoped>
/*
  Leaflet requires the map container to have an explicit height.
  flex-1 + min-h-0 in the parent handles this in the flex layout,
  but we add a fallback min-height for robustness.
*/
[role='application'] {
    min-height: 300px;
}
</style>
