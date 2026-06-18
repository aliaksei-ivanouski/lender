<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { CalendarDate, DateFormatter, parseDate, today, getLocalTimeZone } from '@internationalized/date';
import {
    DatePickerRoot,
    DatePickerTrigger,
    DatePickerContent,
    DatePickerCalendar,
    DatePickerHeader,
    DatePickerPrev,
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
    minDate?: string | null;
    maxDate?: string | null;
}>(), {
    modelValue: null,
    placeholder: 'mm/dd/yyyy',
    minDate: null,
    maxDate: null,
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

// Convert minDate/maxDate ISO strings → DateValue for DatePickerRoot bounds
const minValue = computed<DateValue | undefined>(() => {
    if (!props.minDate) return undefined;
    try {
        return parseDate(props.minDate);
    } catch {
        return undefined;
    }
});

const maxValue = computed<DateValue | undefined>(() => {
    if (!props.maxDate) return undefined;
    try {
        return parseDate(props.maxDate);
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

// Placeholder drives the calendar view (month/year navigation)
// Initialize from current modelValue or today
const calendarPlaceholder = ref<DateValue>(
    dateValue.value ?? today(getLocalTimeZone()),
);

// Sync placeholder when modelValue changes externally so the calendar view follows
// the selected date when the popover re-opens
watch(dateValue, (val) => {
    if (val) calendarPlaceholder.value = val;
});

// Year range: 2015 to current year + 10
const currentYear = new Date().getFullYear();
const yearRange = Array.from(
    { length: currentYear + 10 - 2015 + 1 },
    (_, i) => 2015 + i,
);

const monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

// Writable computeds for month/year selects — v-model keeps both fields in sync
const monthModel = computed<number>({
    get: () => (calendarPlaceholder.value as CalendarDate).month,
    set: (m) => {
        const cur = calendarPlaceholder.value as CalendarDate;
        calendarPlaceholder.value = new CalendarDate(cur.year, m, 1);
    },
});

const yearModel = computed<number>({
    get: () => (calendarPlaceholder.value as CalendarDate).year,
    set: (y) => {
        const cur = calendarPlaceholder.value as CalendarDate;
        calendarPlaceholder.value = new CalendarDate(y, cur.month, 1);
    },
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
        v-model:placeholder="calendarPlaceholder"
        locale="en-US"
        :granularity="'day'"
        :close-on-select="true"
        :min-value="minValue"
        :max-value="maxValue"
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

            <!-- Popover calendar content — portaled to body so it escapes the map stacking context -->
            <Teleport to="body">
            <DatePickerContent
                align="start"
                class="z-[1200] mt-1 rounded-lg border bg-popover p-3 shadow-md outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95"
            >
                <DatePickerCalendar v-slot="{ weekDays, grid }">
                    <!-- Header: prev / month+year selects / next -->
                    <DatePickerHeader class="mb-2 flex items-center justify-between gap-1">
                        <DatePickerPrev
                            class="inline-flex size-7 shrink-0 items-center justify-center rounded-md border bg-transparent hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :aria-label="'Go to previous month'"
                        >
                            <ChevronLeft class="size-4" />
                        </DatePickerPrev>

                        <!-- Month + Year selects replace the heading text for richer navigation -->
                        <div class="flex items-center gap-1">
                            <select
                                v-model.number="monthModel"
                                aria-label="Month"
                                class="h-7 rounded border border-input bg-background px-1 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <option
                                    v-for="(name, idx) in monthNames"
                                    :key="idx + 1"
                                    :value="idx + 1"
                                >{{ name }}</option>
                            </select>

                            <select
                                v-model.number="yearModel"
                                aria-label="Year"
                                class="h-7 rounded border border-input bg-background px-1 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <option
                                    v-for="year in yearRange"
                                    :key="year"
                                    :value="year"
                                >{{ year }}</option>
                            </select>
                        </div>

                        <DatePickerNext
                            class="inline-flex size-7 shrink-0 items-center justify-center rounded-md border bg-transparent hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
                                            'data-[disabled]:pointer-events-none data-[disabled]:opacity-40 data-[disabled]:cursor-not-allowed',
                                            'data-[unavailable]:pointer-events-none data-[unavailable]:opacity-40 data-[unavailable]:cursor-not-allowed',
                                            'data-[today]:font-semibold',
                                        )"
                                    />
                                </DatePickerCell>
                            </DatePickerGridRow>
                        </DatePickerGridBody>
                    </DatePickerGrid>
                </DatePickerCalendar>
            </DatePickerContent>
            </Teleport>
        </div>
    </DatePickerRoot>
</template>
