<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FilterBar from '@/components/events/FilterBar.vue';
import EventCard from '@/components/events/EventCard.vue';
import EventEmptyState from '@/components/events/EventEmptyState.vue';
import EventErrorState from '@/components/events/EventErrorState.vue';
import EventCardSkeleton from '@/components/events/EventCardSkeleton.vue';
import { useEventsData } from '@/composables/useEventsData';
import type { EventFilters } from '@/types/data';

const props = defineProps<{
    filters: EventFilters;
    statuses: string[];
    cities: string[];
}>();

const { form, rows, total, loading, error, hasLoadedOnce, loadedBytes, loadedMs, hasMore, loadMore, setFilters, applyFilters, retry } =
    useEventsData(props.filters);

const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

const loadedSize = computed(() => {
    const kb = loadedBytes.value / 1024;
    return kb < 1024 ? `${kb.toFixed(1)} KB` : `${(kb / 1024).toFixed(2)} MB`;
});

const loadedSeconds = computed(() => (loadedMs.value / 1000).toFixed(1));

function onApply(): void {
    applyFilters();
}

onMounted(() => {
    observer = new IntersectionObserver(
        (entries) => {
            if (entries[0]?.isIntersecting && hasMore.value && !loading.value) {
                void loadMore();
            }
        },
        { rootMargin: '400px' },
    );
    if (sentinel.value) {
        observer.observe(sentinel.value);
    }
    void loadMore();
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <Head title="Event Visuals 1" />

    <div class="flex flex-col gap-6 p-6">
        <!-- Page header -->
        <header class="flex flex-col gap-1">
            <div class="flex items-baseline gap-3">
                <h1 class="text-2xl font-bold tracking-tight">Event Visuals 1</h1>
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
                Browse upcoming events in a visual card grid. Filter by status, date range, or location.
            </p>
        </header>

        <!-- Filter bar -->
        <FilterBar :model-value="form" :statuses="statuses" :cities="cities" :loading="loading" @update:model-value="setFilters" @apply="onApply" />

        <!-- Card grid region -->
        <section aria-label="Events grid" aria-live="polite" aria-atomic="false">
            <!-- Initial skeleton -->
            <div
                v-if="!hasLoadedOnce && loading"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                aria-label="Loading events"
            >
                <EventCardSkeleton v-for="n in 12" :key="n" />
            </div>

            <!-- Initial error -->
            <EventErrorState v-else-if="error && !hasLoadedOnce" :message="error" @retry="retry" />

            <!-- Loaded content -->
            <template v-else>
                <TransitionGroup
                    v-if="rows.length > 0"
                    tag="div"
                    name="card"
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                >
                    <EventCard
                        v-for="event in rows"
                        :key="event.id"
                        :event="event"
                        class="card-item"
                        tabindex="0"
                    />
                </TransitionGroup>

                <!-- Empty state -->
                <EventEmptyState v-if="hasLoadedOnce && !loading && rows.length === 0" />

                <!-- Inline error on subsequent loads -->
                <EventErrorState v-if="error && hasLoadedOnce" :message="error" class="mt-4" @retry="retry" />
            </template>
        </section>

        <!-- IntersectionObserver sentinel -->
        <div ref="sentinel" aria-hidden="true" />

        <!-- Footer status -->
        <footer class="py-1 text-sm text-muted-foreground">
            <span v-if="loading && hasLoadedOnce" aria-label="Loading more events" aria-live="polite">Loading more…</span>
            <span v-else-if="hasLoadedOnce && !error">
                Loaded {{ loadedSize }} in {{ loadedSeconds }}s
            </span>
        </footer>
    </div>
</template>

<style scoped>
/* Card enter animation: fade + slide up */
.card-enter-active {
    transition:
        opacity 0.35s ease,
        transform 0.35s ease;
}

.card-enter-from {
    opacity: 0;
    transform: translateY(16px);
}

.card-enter-to {
    opacity: 1;
    transform: translateY(0);
}

/* Stagger via nth-child delay — capped at 10 items to avoid long waits */
.card-item:nth-child(1)  { transition-delay: 0ms; }
.card-item:nth-child(2)  { transition-delay: 40ms; }
.card-item:nth-child(3)  { transition-delay: 80ms; }
.card-item:nth-child(4)  { transition-delay: 120ms; }
.card-item:nth-child(5)  { transition-delay: 160ms; }
.card-item:nth-child(6)  { transition-delay: 200ms; }
.card-item:nth-child(7)  { transition-delay: 240ms; }
.card-item:nth-child(8)  { transition-delay: 280ms; }
.card-item:nth-child(9)  { transition-delay: 320ms; }
.card-item:nth-child(10) { transition-delay: 360ms; }

/* Respect reduced-motion preference */
@media (prefers-reduced-motion: reduce) {
    .card-enter-active {
        transition: none;
    }

    .card-enter-from {
        opacity: 1;
        transform: none;
    }

    .card-item:nth-child(n) {
        transition-delay: 0ms;
    }
}
</style>
