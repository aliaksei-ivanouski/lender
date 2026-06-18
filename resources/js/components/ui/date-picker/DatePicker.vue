<script setup lang="ts">
import { computed } from 'vue';
import { CalendarDate, DateFormatter, parseDate } from '@internationalized/date';
import {
    DatePickerRoot,
    DatePickerTrigger,
    DatePickerContent,
    DatePickerCalendar,
    DatePickerHeader,
    DatePickerPrev,
    DatePickerHeading,
    DatePickerNext,
    DatePickerGrid,
    DatePickerGridHead,
    DatePickerGridRow,
    DatePickerHeadCell,
    DatePickerGridBody,
    DatePickerCell,
    DatePickerCellTrigger,
} from 'reka-ui';
import type { DateValue } from 'reka-ui';
import { ChevronLeft, ChevronRight, CalendarIcon, X } from '@lucide/vue';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<{
    modelValue?: string | null;
    label?: string;
    ariaLabel?: string;
    placeholder?: string;
    id?: string;
}>(), {
    modelValue: null,
    placeholder: 'mm/dd/yyyy',
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | null): void;
}>();

// Force US locale for all display formatting
const usFormatter = new DateFormatter('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });

// Convert ISO string → reka-ui DateValue (CalendarDate), null if empty/invalid
const dateValue = computed<DateValue | undefined>(() => {
    if (!props.modelValue) return undefined;
    try {
        return parseDate(props.modelValue);
    } catch {
        return undefined;
    }
});

// Displayed text in MM/DD/YYYY format
const displayValue = computed<string>(() => {
    if (!dateValue.value) return '';
    // DateFormatter.format() accepts a native Date; convert via CalendarDate
    const cv = dateValue.value as CalendarDate;
    const native = new Date(cv.year, cv.month - 1, cv.day);
    return usFormatter.format(native);
});

function onDateSelect(value: DateValue | undefined): void {
    if (!value) {
        emit('update:modelValue', null);
        return;
    }
    // CalendarDate.toString() yields 'YYYY-MM-DD'
    emit('update:modelValue', value.toString());
}

function onClear(event: MouseEvent): void {
    event.stopPropagation();
    emit('update:modelValue', null);
}
</script>

<template>
    <DatePickerRoot
        :model-value="dateValue"
        locale="en-US"
        :granularity="'day'"
        @update:model-value="onDateSelect"
    >
        <div class="flex flex-col gap-1">
            <label
                v-if="label"
                :for="id"
                class="text-xs text-muted-foreground"
            >{{ label }}</label>

            <!-- Trigger: shows selected date or placeholder -->
            <DatePickerTrigger
                :id="id"
                :aria-label="ariaLabel ?? label ?? placeholder"
                :class="cn(
                    'border-input dark:bg-input/30 focus-visible:border-ring focus-visible:ring-ring/50',
                    'flex h-9 w-40 items-center justify-between gap-1 rounded-md border bg-background',
                    'px-3 text-sm outline-none focus-visible:ring-[3px]',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                )"
            >
                <span :class="displayValue ? 'text-foreground' : 'text-muted-foreground'">
                    {{ displayValue || placeholder }}
                </span>
                <span class="ml-auto flex items-center gap-1">
                    <!-- Clear button — only visible when a date is selected -->
                    <button
                        v-if="modelValue"
                        type="button"
                        :aria-label="`Clear date`"
                        class="rounded focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        @click="onClear"
                    >
                        <X class="size-3.5 text-muted-foreground hover:text-foreground" />
                    </button>
                    <CalendarIcon class="size-4 shrink-0 text-muted-foreground" />
                </span>
            </DatePickerTrigger>

            <!-- Popover calendar content -->
            <DatePickerContent
                align="start"
                class="z-50 mt-1 rounded-lg border bg-popover p-3 shadow-md outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95"
            >
                <DatePickerCalendar v-slot="{ weekDays, grid }">
                    <!-- Header: prev / heading / next -->
                    <DatePickerHeader class="mb-2 flex items-center justify-between">
                        <DatePickerPrev
                            class="inline-flex size-7 items-center justify-center rounded-md border bg-transparent hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :aria-label="'Go to previous month'"
                        >
                            <ChevronLeft class="size-4" />
                        </DatePickerPrev>

                        <DatePickerHeading class="text-sm font-medium" />

                        <DatePickerNext
                            class="inline-flex size-7 items-center justify-center rounded-md border bg-transparent hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :aria-label="'Go to next month'"
                        >
                            <ChevronRight class="size-4" />
                        </DatePickerNext>
                    </DatePickerHeader>

                    <!-- One grid per month (numberOfMonths defaults to 1) -->
                    <DatePickerGrid
                        v-for="month in grid"
                        :key="month.value.toString()"
                    >
                        <DatePickerGridHead>
                            <DatePickerGridRow class="flex">
                                <DatePickerHeadCell
                                    v-for="day in weekDays"
                                    :key="day"
                                    class="w-9 text-center text-xs font-normal text-muted-foreground"
                                >
                                    {{ day }}
                                </DatePickerHeadCell>
                            </DatePickerGridRow>
                        </DatePickerGridHead>

                        <DatePickerGridBody>
                            <DatePickerGridRow
                                v-for="(weekDates, idx) in month.rows"
                                :key="idx"
                                class="flex"
                            >
                                <DatePickerCell
                                    v-for="date in weekDates"
                                    :key="date.toString()"
                                    :date="date"
                                    class="p-0"
                                >
                                    <DatePickerCellTrigger
                                        :day="date"
                                        :month="month.value"
                                        :class="cn(
                                            'inline-flex size-9 items-center justify-center rounded-md text-sm transition-colors',
                                            'hover:bg-accent hover:text-accent-foreground',
                                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            'data-[selected]:bg-primary data-[selected]:text-primary-foreground data-[selected]:hover:bg-primary/90',
                                            'data-[outside-view]:text-muted-foreground data-[outside-view]:opacity-50',
                                            'data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
                                            'data-[today]:font-semibold',
                                        )"
                                    />
                                </DatePickerCell>
                            </DatePickerGridRow>
                        </DatePickerGridBody>
                    </DatePickerGrid>
                </DatePickerCalendar>
            </DatePickerContent>
        </div>
    </DatePickerRoot>
</template>
