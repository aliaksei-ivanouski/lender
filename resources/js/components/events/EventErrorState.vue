<script setup lang="ts">
import { Button } from '@/components/ui/button';

defineProps<{
    message?: string;
}>();

const emit = defineEmits<{
    (e: 'retry'): void;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-4 rounded-lg border border-destructive/30 bg-destructive/5 py-12 text-center"
        role="alert"
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            class="size-10 text-destructive opacity-70"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="1.5"
            aria-hidden="true"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"
            />
        </svg>
        <div>
            <p class="text-sm font-medium text-destructive">Failed to load events</p>
            <p v-if="message" class="mt-1 text-xs text-muted-foreground">{{ message }}</p>
        </div>
        <Button variant="outline" size="sm" @click="emit('retry')">Retry</Button>
    </div>
</template>
