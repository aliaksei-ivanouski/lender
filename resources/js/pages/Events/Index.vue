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

const { form, rows, total, loading, error, hasLoadedOnce, loadedBytes, loadedMs, hasMore, loadMore, applyFilters, retry } =
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
    // Initial load — bypass debounce via loadMore directly
    void loadMore();
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <Head title="Events" />

    <div class="flex flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold">Events</h1>
            <p class="text-sm text-muted-foreground">
                {{ total !== null ? `${total.toLocaleString()} total events` : '—' }}
            </p>
        </div>

        <FilterBar v-model="form" :statuses="statuses" :cities="cities" :loading="loading" @apply="onApply" />

        <div aria-live="polite" aria-atomic="false">
            <!-- Initial skeleton loading -->
            <div v-if="!hasLoadedOnce && loading" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <EventCardSkeleton v-for="n in 12" :key="n" />
            </div>

            <!-- Error state -->
            <EventErrorState v-else-if="error && !hasLoadedOnce" :message="error" @retry="retry" />

            <!-- Loaded: card grid -->
            <template v-else>
                <div
                    v-if="rows.length > 0"
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                >
                    <EventCard v-for="event in rows" :key="event.id" :event="event" />
                </div>

                <!-- Empty state -->
                <EventEmptyState v-if="hasLoadedOnce && !loading && rows.length === 0" />

                <!-- Inline error on subsequent loads -->
                <EventErrorState v-if="error && hasLoadedOnce" :message="error" class="mt-4" @retry="retry" />
            </template>
        </div>

        <!-- IntersectionObserver sentinel -->
        <div ref="sentinel" aria-hidden="true" />

        <!-- Load status / footer -->
        <div class="py-2 text-sm text-muted-foreground">
            <span v-if="loading && hasLoadedOnce" aria-label="Loading more events">Loading more…</span>
            <span v-else-if="hasLoadedOnce && !error">Loaded {{ loadedSize }} in {{ loadedSeconds }}s</span>
        </div>
    </div>
</template>
