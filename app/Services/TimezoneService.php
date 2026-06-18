<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Single source for event-local time formatting (ADR-004, DEC-005 R1).
 *
 * Under Revision R1 the IANA timezone is supplied directly by the caller
 * (resolved from City->timezone via ReverseGeocoder).  This service no
 * longer performs any city-name → timezone map lookup; it performs only
 * time formatting and UTC range conversion.
 */
final class TimezoneService
{
    /**
     * Returns formatted event-local datetime strings for frontend rendering.
     *
     * Converts the raw UTC unix timestamp stored in events.created_time to
     * the local time expressed in the given IANA timezone.  All keys in the
     * returned array are pre-formatted strings; the frontend renders them
     * without any additional date-library computation.
     *
     * @param  int  $unixTimestamp  UTC unix timestamp (events.created_time)
     * @param  string  $timezone  IANA timezone identifier from City->timezone
     *                            (e.g. "America/New_York", "Europe/Paris")
     * @return array{starts_at_local: string, starts_at_date: string, tz_label: string, tz_identifier: string, utc_timestamp: int}
     */
    public function formatEventTime(int $unixTimestamp, string $timezone): array
    {
        $local = CarbonImmutable::createFromTimestampUTC($unixTimestamp)->setTimezone($timezone);

        return [
            'starts_at_local' => $local->format('g:i A'),     // e.g. "8:00 PM"
            'starts_at_date' => $local->format('D, M j, Y'), // e.g. "Tue, Jan 7, 2025"
            'tz_label' => $local->format('T'),          // e.g. "CET", "EST"
            'tz_identifier' => $timezone,                    // e.g. "Europe/Paris"
            'utc_timestamp' => $unixTimestamp,               // raw unix ts for JS fallback
        ];
    }

    /**
     * Converts a local calendar date string to a UTC unix timestamp range.
     *
     * Given a YYYY-MM-DD date in the given IANA timezone, returns the
     * inclusive [startUtcUnix, endUtcUnix] range covering that full calendar
     * day.  Used by EventController to translate the from/to date filter into
     * a BETWEEN clause on events.created_time (DEC-006, ADR-009).
     *
     * When $timezone is null (no city filter active), UTC day boundaries with
     * a ±1-day buffer are applied so that any event-local-day interpretation
     * within UTC-12..UTC+14 is covered (ADR-009 trade-off note).
     *
     * @param  string  $localDate  Calendar date in YYYY-MM-DD format
     * @param  string|null  $timezone  IANA timezone identifier from City->timezone,
     *                                 or null to use the buffered UTC fallback
     * @return array{0: int, 1: int} [startUtcUnix, endUtcUnix]
     */
    public function localDateToUtcRange(string $localDate, ?string $timezone): array
    {
        if ($timezone !== null) {
            $utcStart = CarbonImmutable::parse($localDate, $timezone)
                ->startOfDay()
                ->utc()
                ->getTimestamp();

            $utcEnd = CarbonImmutable::parse($localDate, $timezone)
                ->endOfDay()
                ->utc()
                ->getTimestamp();

            return [$utcStart, $utcEnd];
        }

        // No timezone supplied: use UTC with a ±1-day buffer to ensure any
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
}
