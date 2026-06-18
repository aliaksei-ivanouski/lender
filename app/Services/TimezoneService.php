<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CityAnchor;
use Carbon\CarbonImmutable;

/**
 * Single source for event-local time formatting (ADR-004).
 *
 * Timezone is derived at read time from the static CITY_ANCHOR → IANA
 * timezone map using location_city as the lookup key; it is NOT stored
 * as a separate column (DEC-005).
 */
final class TimezoneService
{
    /**
     * Maps a city name to its IANA timezone identifier.
     *
     * Falls back to 'UTC' if the city is not in the CityAnchor map so that
     * callers always receive a usable timezone string.
     */
    public function ianaTimezone(string $city): string
    {
        return CityAnchor::timezoneMap()[$city] ?? 'UTC';
    }

    /**
     * Returns formatted event-local datetime strings for frontend rendering.
     *
     * Converts the raw UTC unix timestamp stored in events.created_time to
     * the local time of the event's city.  All keys in the returned array
     * are pre-formatted strings; the frontend renders them without any
     * additional date-library computation.
     *
     * @param  int  $unixTimestamp  UTC unix timestamp (events.created_time)
     * @param  string  $city  Value of events.location_city (bare city name)
     * @return array{starts_at_local: string, starts_at_date: string, tz_label: string, tz_identifier: string, utc_timestamp: int}
     */
    public function formatEventTime(int $unixTimestamp, string $city): array
    {
        $tz = $this->ianaTimezone($city);
        $local = CarbonImmutable::createFromTimestampUTC($unixTimestamp)->setTimezone($tz);

        return [
            'starts_at_local' => $local->format('g:i A'),     // e.g. "8:00 PM"
            'starts_at_date' => $local->format('D, M j, Y'), // e.g. "Tue, Jan 7, 2025"
            'tz_label' => $local->format('T'),          // e.g. "CET", "EST"
            'tz_identifier' => $tz,                          // e.g. "Europe/Paris"
            'utc_timestamp' => $unixTimestamp,               // raw unix ts for JS fallback
        ];
    }

    /**
     * Converts a local calendar date string to a UTC unix timestamp range.
     *
     * Given a YYYY-MM-DD date in the event city's local timezone, returns
     * the inclusive [startUtcUnix, endUtcUnix] range that covers that full
     * calendar day.  Used by EventController to translate the from/to date
     * filter into a BETWEEN clause on events.created_time (DEC-006, ADR-009).
     *
     * When $city is null (no city filter active), UTC day boundaries with a
     * ±1-day buffer are applied so that any event-local-day interpretation
     * within UTC-12..UTC+14 is covered (ADR-009 trade-off note).
     *
     * @param  string  $localDate  Calendar date in YYYY-MM-DD format
     * @param  string|null  $city  events.location_city value, or null for buffered UTC
     * @return array{0: int, 1: int} [startUtcUnix, endUtcUnix]
     */
    public function localDateToUtcRange(string $localDate, ?string $city): array
    {
        if ($city !== null) {
            $tz = $this->ianaTimezone($city);
        } else {
            // No city supplied: use UTC with a ±1-day buffer to ensure any
            // event-local interpretation of the requested day is included.
            $utcStart = CarbonImmutable::parse($localDate, 'UTC')
                ->subDay()
                ->startOfDay()
                ->utc()
                ->getTimestamp();

            $utcEnd = CarbonImmutable::parse($localDate, 'UTC')
                ->addDay()
                ->endOfDay()
                ->utc()
                ->getTimestamp();

            return [$utcStart, $utcEnd];
        }

        $utcStart = CarbonImmutable::parse($localDate, $tz)
            ->startOfDay()
            ->utc()
            ->getTimestamp();

        $utcEnd = CarbonImmutable::parse($localDate, $tz)
            ->endOfDay()
            ->utc()
            ->getTimestamp();

        return [$utcStart, $utcEnd];
    }
}
