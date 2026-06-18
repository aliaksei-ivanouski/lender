<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface EventDetail {
    id: string;
    type: string;
    status: string;
    created_time: number | null;
    latitude: number | null;
    longitude: number | null;
    payload: Record<string, unknown>;
}

const props = defineProps<{ event: EventDetail }>();

const prettyPayload = computed(() => JSON.stringify(props.event.payload, null, 2));
</script>

<template>
    <Head :title="`Event ${event.id}`" />

    <div class="flex flex-col gap-4 p-4">
        <Link href="/events" class="text-sm text-primary hover:underline">← Back to events</Link>

        <h1 class="text-lg font-semibold">Event {{ event.id }}</h1>

        <pre class="overflow-x-auto rounded-lg border p-4 text-xs">{{ prettyPayload }}</pre>
    </div>
</template>
