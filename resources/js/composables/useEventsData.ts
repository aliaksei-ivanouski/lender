import type { ComputedRef, Ref } from 'vue';
import { computed, reactive, ref } from 'vue';
import type { BadgeVariants } from '@/components/ui/badge';
import type { EventFilters, EventListItem } from '@/types/data';

export type { EventFilters };

export interface UseEventsDataReturn {
    form: EventFilters;
    rows: Ref<EventListItem[]>;
    page: Ref<number>;
    lastPage: Ref<number | null>;
    total: Ref<number | null>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    hasLoadedOnce: Ref<boolean>;
    loadedBytes: Ref<number>;
    loadedMs: Ref<number>;
    hasMore: ComputedRef<boolean>;
    loadMore: () => Promise<void>;
    applyFilters: (newFilters?: Partial<EventFilters>) => void;
    retry: () => void;
    statusVariant: (status: string) => BadgeVariants['variant'];
}

export function useEventsData(initialFilters: EventFilters): UseEventsDataReturn {
    const form = reactive<EventFilters>({
        status: initialFilters.status ?? null,
        from: initialFilters.from ?? null,
        to: initialFilters.to ?? null,
        location_city: initialFilters.location_city ?? null,
    });

    const rows = ref<EventListItem[]>([]);
    const page = ref(0);
    const lastPage = ref<number | null>(null);
    const total = ref<number | null>(null);
    const loadedBytes = ref(0);
    const loadedMs = ref(0);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const hasLoadedOnce = ref(false);

    const hasMore = computed(() => lastPage.value === null || page.value < lastPage.value);

    let debounceTimer: ReturnType<typeof setTimeout> | null = null;

    async function loadMore(): Promise<void> {
        if (loading.value || !hasMore.value) {
            return;
        }
        loading.value = true;
        error.value = null;

        const params = new URLSearchParams({ page: String(page.value + 1) });
        if (form.status) params.set('status', form.status);
        if (form.from) params.set('from', form.from);
        if (form.to) params.set('to', form.to);
        if (form.location_city) params.set('location_city', form.location_city);

        try {
            const response = await fetch(`/events/data?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                error.value = `Failed to load events (HTTP ${response.status})`;
                return;
            }

            const payload = (await response.json()) as {
                data: EventListItem[];
                current_page: number;
                last_page: number;
                total: number;
                stats: { ms: number; bytes: number };
            };

            rows.value.push(...payload.data);
            page.value = payload.current_page;
            lastPage.value = payload.last_page;
            total.value = payload.total;
            loadedBytes.value += payload.stats.bytes;
            loadedMs.value += payload.stats.ms;
            hasLoadedOnce.value = true;
        } catch {
            error.value = 'Network error: unable to load events. Please try again.';
        } finally {
            loading.value = false;
        }
    }

    function resetState(): void {
        rows.value = [];
        page.value = 0;
        lastPage.value = null;
        total.value = null;
        loadedBytes.value = 0;
        loadedMs.value = 0;
        hasLoadedOnce.value = false;
        error.value = null;
    }

    function applyFilters(newFilters?: Partial<EventFilters>): void {
        if (newFilters) {
            if (newFilters.status !== undefined) form.status = newFilters.status;
            if (newFilters.from !== undefined) form.from = newFilters.from;
            if (newFilters.to !== undefined) form.to = newFilters.to;
            if (newFilters.location_city !== undefined) form.location_city = newFilters.location_city;
        }

        resetState();

        // Debounce the actual fetch by 400ms to avoid excessive calls
        if (debounceTimer !== null) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(() => {
            debounceTimer = null;
            void loadMore();
        }, 400);
    }

    function retry(): void {
        error.value = null;
        void loadMore();
    }

    function statusVariant(status: string): BadgeVariants['variant'] {
        switch (status) {
            case 'published':
                return 'default';
            case 'cancelled':
                return 'destructive';
            case 'sold_out':
                return 'secondary';
            default:
                return 'outline';
        }
    }

    return {
        form,
        rows,
        page,
        lastPage,
        total,
        loading,
        error,
        hasLoadedOnce,
        loadedBytes,
        loadedMs,
        hasMore,
        loadMore,
        applyFilters,
        retry,
        statusVariant,
    };
}
