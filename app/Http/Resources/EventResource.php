<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Event;
use App\Services\TimezoneService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms an Event model into the EventListItem TS contract shape.
 *
 * @see resources/js/types/data.ts EventListItem
 *
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Event $event */
        $event = $this->resource;

        $city = $event->city;
        $timezone = $city !== null ? $city->timezone : 'UTC';

        /** @var TimezoneService $tzService */
        $tzService = app(TimezoneService::class);

        $tzFields = $tzService->formatEventTime($event->created_time, $timezone);

        // Resolve ends_at from payload if available
        $endsAtLocal = null;
        $payload = $event->payload ?? [];
        $endsAtUnix = $payload['schedule']['ends_at'] ?? null;
        if ($endsAtUnix !== null && is_numeric($endsAtUnix)) {
            $endsAtFormatted = $tzService->formatEventTime((int) $endsAtUnix, $timezone);
            $endsAtLocal = $endsAtFormatted['starts_at_local'];
        }

        return [
            'id' => (string) $event->id,
            'type' => $event->type,
            'status' => $event->status,
            'name' => $payload['name'] ?? '',
            'description' => $payload['description'] ?? '',
            'venue_name' => $payload['venue']['name'] ?? null,
            'location_city' => $event->location_city,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
            'created_time' => $event->created_time,
            'starts_at_local' => $tzFields['starts_at_local'],
            'starts_at_date' => $tzFields['starts_at_date'],
            'tz_label' => $tzFields['tz_label'],
            'tz_identifier' => $tzFields['tz_identifier'],
            'utc_timestamp' => $tzFields['utc_timestamp'],
            'ends_at_local' => $endsAtLocal,
            'cover_image_url' => $event->coverImage?->url,
            'user' => $event->user,
        ];
    }
}
