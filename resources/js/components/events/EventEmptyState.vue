<script setup lang="ts">
// No props — purely presentational
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-4 py-16 text-center text-muted-foreground">
        <svg
            xmlns="http://www.w3.org/2000/svg"
            class="size-12 opacity-40"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="1"
            aria-hidden="true"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
            />
        </svg>
        <div>
            <p class="text-sm font-medium">No events found</p>
            <p class="mt-1 text-xs">No events match your current filters. Try adjusting or clearing the filters.</p>
        </div>
    </div>
</template>
