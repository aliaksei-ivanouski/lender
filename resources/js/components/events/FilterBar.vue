<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { EventFilters } from '@/types/data';

const props = defineProps<{
    modelValue: EventFilters;
    statuses: string[];
    cities: string[];
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: EventFilters): void;
    (e: 'apply'): void;
}>();

function update(field: keyof EventFilters, value: string): void {
    emit('update:modelValue', { ...props.modelValue, [field]: value || null });
}

function onSelectChange(field: keyof EventFilters, event: Event): void {
    update(field, (event.target as HTMLSelectElement).value);
    emit('apply');
}

function onDateInput(field: keyof EventFilters, event: Event): void {
    update(field, (event.target as HTMLInputElement).value);
}

function onSubmit(): void {
    emit('apply');
}
</script>

<template>
    <form
        role="search"
        class="flex flex-wrap items-end gap-3 rounded-lg border bg-card p-3"
        :aria-busy="loading ? 'true' : 'false'"
        @submit.prevent="onSubmit"
    >
        <div class="flex flex-col gap-1">
            <label class="text-xs text-muted-foreground" for="filter-status">Status</label>
            <select
                id="filter-status"
                :value="modelValue.status ?? ''"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                @change="onSelectChange('status', $event)"
            >
                <option value="">All</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs text-muted-foreground" for="filter-from">From</label>
            <input
                id="filter-from"
                type="date"
                :value="modelValue.from ?? ''"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                @input="onDateInput('from', $event)"
            />
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs text-muted-foreground" for="filter-to">To</label>
            <input
                id="filter-to"
                type="date"
                :value="modelValue.to ?? ''"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                @input="onDateInput('to', $event)"
            />
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs text-muted-foreground" for="filter-location">Location</label>
            <select
                id="filter-location"
                :value="modelValue.location_city ?? ''"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                @change="onSelectChange('location_city', $event)"
            >
                <option value="">All</option>
                <option v-for="city in cities" :key="city" :value="city">{{ city }}</option>
            </select>
        </div>

        <Button type="submit" :disabled="loading">Filter</Button>
    </form>
</template>
