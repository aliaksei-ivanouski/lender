<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatStatus } from '@/lib/format';
import type { ShowEvent, Attendee } from '@/types/data';

const props = defineProps<{
    event: ShowEvent;
    attendees: Attendee[];
    attendeesCount: number;
    isRegistered: boolean;
    isAuthenticated: boolean;
}>();

// ── Registration state ────────────────────────────────────────────────────────

const processing = ref(false);

function register() {
    if (processing.value) return;
    processing.value = true;
    router.post(`/events/${props.event.id}/registrations`, {}, {
        onFinish: () => { processing.value = false; },
    });
}

function unregister() {
    if (processing.value) return;
    processing.value = true;
    router.delete(`/events/${props.event.id}/registrations`, {
        onFinish: () => { processing.value = false; },
    });
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function statusVariant(status: string) {
    switch (status) {
        case 'published': return 'default';
        case 'cancelled': return 'destructive';
        case 'sold_out':  return 'secondary';
        default:          return 'outline';
    }
}

const coverImage = computed(() => {
    if (props.event.cover_image_url) return props.event.cover_image_url;
    return props.event.images[0]?.url ?? null;
});

const thumbnails = computed(() =>
    props.event.images.length > 1 ? props.event.images.slice(1, 6) : [],
);

const extraAttendees = computed(() =>
    props.attendeesCount > 20 ? props.attendeesCount - 20 : 0,
);
</script>

<template>
    <Head :title="event.name" />

    <div class="mx-auto max-w-3xl space-y-8 px-4 py-8">

        <!-- Back link -->
        <Link
            href="/events-visual-1"
            class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            aria-label="Back to events list"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
            Back to events
        </Link>

        <!-- Hero image -->
        <div class="overflow-hidden rounded-xl border bg-muted">
            <div class="aspect-video w-full">
                <img
                    v-if="coverImage"
                    :src="coverImage"
                    :alt="event.name"
                    class="h-full w-full object-cover"
                />
                <div
                    v-else
                    class="flex h-full w-full items-center justify-center text-muted-foreground"
                    aria-hidden="true"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-16 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
            </div>

            <!-- Thumbnail strip (shown when there are extra images) -->
            <div v-if="thumbnails.length > 0" class="flex gap-2 border-t p-2" role="list" aria-label="Additional event images">
                <div
                    v-for="img in thumbnails"
                    :key="img.id"
                    class="aspect-video w-24 shrink-0 overflow-hidden rounded"
                    role="listitem"
                >
                    <img
                        :src="img.url"
                        :alt="img.alt ?? event.name"
                        class="h-full w-full object-cover"
                    />
                </div>
            </div>
        </div>

        <!-- Event header -->
        <div class="space-y-3">
            <div class="flex flex-wrap items-start gap-2">
                <Badge :variant="statusVariant(event.status)" class="shrink-0">
                    {{ formatStatus(event.status) }}
                </Badge>
                <Badge variant="outline" class="shrink-0 capitalize">
                    {{ event.type.replace(/_/g, ' ') }}
                </Badge>
            </div>
            <h1 class="text-2xl font-bold leading-tight tracking-tight">
                {{ event.name }}
            </h1>
        </div>

        <!-- Meta details -->
        <dl class="grid gap-3 rounded-lg border bg-card p-4 text-sm sm:grid-cols-2">
            <!-- Date / time -->
            <div class="flex items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 size-4 shrink-0 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <div>
                    <dt class="font-medium text-foreground">Date &amp; Time</dt>
                    <dd class="text-muted-foreground">
                        {{ event.starts_at_date }}
                        <span class="mx-1" aria-hidden="true">·</span>
                        {{ event.starts_at_local }}
                        <span v-if="event.ends_at_local"> – {{ event.ends_at_local }}</span>
                        <span class="ml-1 font-medium text-foreground">{{ event.tz_label }}</span>
                    </dd>
                </div>
            </div>

            <!-- Location -->
            <div v-if="event.venue_name || event.location_city" class="flex items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 size-4 shrink-0 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <div>
                    <dt class="font-medium text-foreground">Location</dt>
                    <dd class="text-muted-foreground">
                        <span v-if="event.venue_name">{{ event.venue_name }}<br /></span>
                        <span v-if="event.location_city">{{ event.location_city }}</span>
                    </dd>
                </div>
            </div>
        </dl>

        <!-- Description -->
        <section v-if="event.description" aria-labelledby="description-heading">
            <h2 id="description-heading" class="mb-2 text-base font-semibold">About this event</h2>
            <p class="whitespace-pre-wrap text-sm leading-relaxed text-muted-foreground">{{ event.description }}</p>
        </section>

        <!-- Registration CTA -->
        <section
            class="rounded-xl border bg-card p-6"
            aria-labelledby="registration-heading"
        >
            <h2 id="registration-heading" class="mb-4 text-base font-semibold">Registration</h2>

            <!-- Guest -->
            <template v-if="!isAuthenticated">
                <p class="mb-4 text-sm text-muted-foreground">You must be logged in to register for this event.</p>
                <Button as="a" href="/login">
                    Log in to register
                </Button>
            </template>

            <!-- Authenticated, not registered -->
            <template v-else-if="!isRegistered">
                <p class="mb-4 text-sm text-muted-foreground">Secure your spot at this event.</p>
                <Button
                    :disabled="processing"
                    :aria-busy="processing"
                    aria-label="Register for this event"
                    @click="register"
                >
                    <span v-if="processing">Registering…</span>
                    <span v-else>Register for this event</span>
                </Button>
            </template>

            <!-- Authenticated, registered -->
            <template v-else>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-sm font-medium text-green-700 dark:text-green-400">You're on the list!</span>
                    </div>
                    <Button
                        variant="outline"
                        :disabled="processing"
                        :aria-busy="processing"
                        aria-label="Unregister from this event"
                        @click="unregister"
                    >
                        <span v-if="processing">Leaving…</span>
                        <span v-else>Unregister</span>
                    </Button>
                </div>
            </template>
        </section>

        <!-- Attendees -->
        <section aria-labelledby="attendees-heading">
            <h2 id="attendees-heading" class="mb-4 text-base font-semibold">
                Attendees
                <span class="ml-1 text-muted-foreground">({{ attendeesCount }})</span>
            </h2>

            <div v-if="attendeesCount === 0" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                Be the first to register!
            </div>

            <ul v-else class="divide-y rounded-lg border" role="list" aria-label="Attendee list">
                <li
                    v-for="(attendee, index) in attendees"
                    :key="index"
                    class="flex items-center gap-3 px-4 py-3"
                >
                    <!-- Avatar initials -->
                    <div
                        class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                        aria-hidden="true"
                    >
                        {{ attendee.name.charAt(0).toUpperCase() }}
                    </div>
                    <span class="text-sm font-medium">{{ attendee.name }}</span>
                    <span class="ml-auto text-xs text-muted-foreground">{{ attendee.registered_at }}</span>
                </li>
            </ul>

            <p v-if="extraAttendees > 0" class="mt-3 text-sm text-muted-foreground">
                … and {{ extraAttendees }} more {{ extraAttendees === 1 ? 'attendee' : 'attendees' }}.
            </p>
        </section>

    </div>
</template>
