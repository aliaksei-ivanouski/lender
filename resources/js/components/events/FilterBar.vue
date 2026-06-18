<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { formatStatus } from '@/lib/format';
import type { DateBounds, EventFilters } from '@/types/data';

const props = defineProps<{
    modelValue: EventFilters;
    statuses: string[];
    cities: string[];
    loading?: boolean;
    dateBounds?: DateBounds | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: EventFilters): void;
    (e: 'apply'): void;
}>();

function update(field: keyof EventFilters, value: string | null): void {
    emit('update:modelValue', { ...props.modelValue, [field]: value || null });
}

function onSelectChange(field: keyof EventFilters, event: Event): void {
    update(field, (event.target as HTMLSelectElement).value);
    emit('apply');
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
                <option v-for="s in statuses" :key="s" :value="s">{{ formatStatus(s) }}</option>
            </select>
        </div>

        <DatePicker
            id="filter-from"
            label="From"
            aria-label="Filter from date"
            :model-value="modelValue.from ?? null"
            :min-date="dateBounds?.min ?? null"
            :max-date="modelValue.to ?? dateBounds?.max ?? null"
            @update:model-value="update('from', $event)"
        />

        <DatePicker
            id="filter-to"
            label="To"
            aria-label="Filter to date"
            :model-value="modelValue.to ?? null"
            :min-date="modelValue.from ?? dateBounds?.min ?? null"
            :max-date="dateBounds?.max ?? null"
            @update:model-value="update('to', $event)"
        />

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
