<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FilterBar from '@/components/events/FilterBar.vue';
import EventEmptyState from '@/components/events/EventEmptyState.vue';
import EventErrorState from '@/components/events/EventErrorState.vue';
import { Badge } from '@/components/ui/badge';
import { useEventsData } from '@/composables/useEventsData';
import type { EventFilters } from '@/types/data';

const props = defineProps<{
    filters: EventFilters;
    statuses: string[];
    cities: string[];
}>();

const { form, rows, total, loading, error, hasLoadedOnce, loadedBytes, loadedMs, hasMore, loadMore, setFilters, applyFilters, retry, statusVariant } =
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
    <Head title="Events" />

    <div class="flex flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold">Events</h1>
            <p class="text-sm text-muted-foreground">
                {{ total !== null ? `${total.toLocaleString()} total events` : '—' }}
            </p>
        </div>

        <FilterBar :model-value="form" :statuses="statuses" :cities="cities" :loading="loading" @update:model-value="setFilters" @apply="onApply" />

        <div aria-live="polite" aria-atomic="false">
            <!-- Initial skeleton loading -->
            <div v-if="!hasLoadedOnce && loading" class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="sticky top-0 border-b border-border bg-muted/60">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Name</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Location</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Type</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Starts</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium text-muted-foreground">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="n in 8" :key="n" class="border-b border-border last:border-0" :class="n % 2 === 0 ? 'bg-muted/20' : ''">
                            <td class="px-4 py-3">
                                <div class="h-4 w-40 animate-pulse rounded bg-muted" />
                                <div class="mt-1 h-3 w-24 animate-pulse rounded bg-muted/60" />
                            </td>
                            <td class="px-4 py-3"><div class="h-4 w-24 animate-pulse rounded bg-muted" /></td>
                            <td class="px-4 py-3"><div class="h-4 w-16 animate-pulse rounded bg-muted" /></td>
                            <td class="px-4 py-3"><div class="h-5 w-20 animate-pulse rounded-full bg-muted" /></td>
                            <td class="px-4 py-3"><div class="h-4 w-32 animate-pulse rounded bg-muted" /></td>
                            <td class="px-4 py-3 text-right"><div class="ml-auto h-4 w-10 animate-pulse rounded bg-muted" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Initial error state -->
            <EventErrorState v-else-if="error && !hasLoadedOnce" :message="error" @retry="retry" />

            <!-- Loaded: data table -->
            <template v-else>
                <div v-if="rows.length > 0" class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead class="sticky top-0 border-b border-border bg-muted/60">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Name</th>
                                <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Location</th>
                                <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Type</th>
                                <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                                <th scope="col" class="px-4 py-3 text-left font-medium text-muted-foreground">Starts</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium text-muted-foreground">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(event, index) in rows"
                                :key="event.id"
                                class="border-b border-border last:border-0 transition-colors hover:bg-accent/30"
                                :class="index % 2 === 0 ? '' : 'bg-muted/20'"
                            >
                                <td class="px-4 py-3">
                                    <span class="font-medium text-foreground">{{ event.name }}</span>
                                    <span v-if="event.venue_name" class="mt-0.5 block text-xs text-muted-foreground">
                                        {{ event.venue_name }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ event.location_city ?? '—' }}
                                </td>
                                <td class="px-4 py-3 capitalize text-muted-foreground">
                                    {{ event.type ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge :variant="statusVariant(event.status)" class="capitalize">
                                        {{ event.status }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    <template v-if="event.starts_at_local">
                                        <span>{{ event.starts_at_local }}</span>
                                        <span v-if="event.starts_at_date" class="mx-1 opacity-40">·</span>
                                        <span v-if="event.starts_at_date">{{ event.starts_at_date }}</span>
                                        <span v-if="event.tz_label" class="mx-1 opacity-40">·</span>
                                        <span v-if="event.tz_label" class="text-xs">{{ event.tz_label }}</span>
                                    </template>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        :href="`/events/${event.id}`"
                                        class="rounded text-xs font-medium text-primary underline-offset-2 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    >
                                        View
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
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
