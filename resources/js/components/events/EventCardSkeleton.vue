<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <div class="flex flex-col overflow-hidden rounded-lg border bg-card shadow-sm" aria-hidden="true">
        <!-- Image placeholder -->
        <Skeleton class="aspect-video w-full rounded-none" />

        <!-- Body placeholder -->
        <div class="flex flex-col gap-3 p-4">
            <!-- Title row -->
            <div class="flex items-start justify-between gap-2">
                <Skeleton class="h-4 w-3/4" />
                <Skeleton class="h-5 w-16 rounded-full" />
            </div>

            <!-- Description lines -->
            <div class="flex flex-col gap-1.5">
                <Skeleton class="h-3 w-full" />
                <Skeleton class="h-3 w-5/6" />
                <Skeleton class="h-3 w-2/3" />
            </div>

            <!-- Meta row -->
            <div class="flex flex-col gap-1.5 pt-1">
                <Skeleton class="h-3 w-1/3" />
                <Skeleton class="h-3 w-1/2" />
            </div>
        </div>
    </div>
</template>
