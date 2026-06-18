<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import type { EventListItem } from '@/types/data';

const props = defineProps<{
    event: EventListItem;
    animate?: boolean;
}>();

function statusVariant(status: string) {
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

// Not used directly but kept for potential parent usage
void props.animate;
</script>

<template>
    <Link
        :href="`/events/${event.id}`"
        class="group flex flex-col overflow-hidden rounded-lg border bg-card text-card-foreground shadow-sm transition-shadow hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    >
        <!-- Cover image -->
        <div class="relative aspect-video w-full overflow-hidden bg-muted">
            <img
                v-if="event.cover_image_url"
                :src="event.cover_image_url"
                :alt="event.name"
                loading="lazy"
                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105 motion-reduce:transition-none"
            />
            <div
                v-else
                class="flex h-full w-full items-center justify-center text-muted-foreground"
                aria-hidden="true"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="size-10 opacity-30"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"
                    />
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"
                    />
                </svg>
            </div>
        </div>

        <!-- Card body -->
        <div class="flex flex-1 flex-col gap-2 p-4">
            <div class="flex items-start justify-between gap-2">
                <h3 class="line-clamp-2 flex-1 text-sm font-semibold leading-snug">
                    {{ event.name }}
                </h3>
                <Badge :variant="statusVariant(event.status)" class="shrink-0">
                    {{ event.status }}
                </Badge>
            </div>

            <p v-if="event.description" class="line-clamp-3 text-xs text-muted-foreground">
                {{ event.description }}
            </p>

            <div class="mt-auto flex flex-col gap-1 pt-2 text-xs text-muted-foreground">
                <span v-if="event.location_city" class="flex items-center gap-1">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="size-3 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                        />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    {{ event.location_city }}
                </span>

                <span class="flex items-center gap-1">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="size-3 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                        />
                    </svg>
                    {{ event.starts_at_date }} · {{ event.starts_at_local }} {{ event.tz_label }}
                </span>
            </div>
        </div>
    </Link>
</template>
