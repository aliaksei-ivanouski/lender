<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Services\TimezoneService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function visualOne(Request $request): Response
    {
        return Inertia::render('Events/VisualOne', $this->sharedListingProps($request));
    }

    public function visualTwo(Request $request): Response
    {
        return Inertia::render('Events/VisualTwo', $this->sharedListingProps($request));
    }

    public function data(Request $request): JsonResponse
    {
        [$events, $stats] = $this->loadListing($request);

        return response()->json([
            'data' => EventResource::collection($events->items()),
            'current_page' => $events->currentPage(),
            'last_page' => $events->lastPage(),
            'total' => $events->total(),
            'stats' => $stats,
        ]);
    }

    public function show(Event $event): Response
    {
        $event->load(['user', 'city', 'images', 'registrations.user']);

        /** @var TimezoneService $tzService */
        $tzService = app(TimezoneService::class);
        $city = $event->city;
        $timezone = $city->timezone ?? 'UTC';
        $tzFields = $tzService->formatEventTime($event->created_time, $timezone);

        $payload = $event->payload ?? [];

        // Resolve ends_at if available
        $endsAtLocal = null;
        $endsAtUnix = $payload['schedule']['ends_at'] ?? null;
        if ($endsAtUnix !== null && is_numeric($endsAtUnix)) {
            $endsAtFormatted = $tzService->formatEventTime((int) $endsAtUnix, $timezone);
            $endsAtLocal = $endsAtFormatted['starts_at_local'];
        }

        // Attendee list: first 20 confirmed attendees (name only — no email/user_id exposed)
        $attendees = $event->registrations()
            ->where('status', 'confirmed')
            ->with('user:id,name')
            ->latest()
            ->take(20)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->user->name,
                'registered_at' => $r->created_at->toDateString(),
            ]);

        $attendeesCount = $event->registrations()->where('status', 'confirmed')->count();

        /** @var User|null $authUser */
        $authUser = auth()->user();
        $isRegistered = $authUser
            ? $event->registrations()
                ->where('user_id', $authUser->id)
                ->where('status', 'confirmed')
                ->exists()
            : false;

        return Inertia::render('Events/Show', [
            'event' => [
                'id' => (string) $event->id,
                'name' => $payload['name'] ?? '',
                'description' => $payload['description'] ?? '',
                'type' => $event->type,
                'status' => $event->status,
                'venue_name' => $payload['venue']['name'] ?? null,
                'location_city' => $event->location_city,
                'latitude' => $event->latitude,
                'longitude' => $event->longitude,
                'starts_at_local' => $tzFields['starts_at_local'],
                'starts_at_date' => $tzFields['starts_at_date'],
                'ends_at_local' => $endsAtLocal,
                'tz_label' => $tzFields['tz_label'],
                'tz_identifier' => $tzFields['tz_identifier'],
                'utc_timestamp' => $tzFields['utc_timestamp'],
                'images' => $event->images->map(fn ($img) => [
                    'id' => $img->id,
                    'url' => $img->url,
                    'alt' => $img->alt ?? null,
                    'sort_order' => $img->sort_order,
                ]),
                'cover_image_url' => $event->coverImage?->url,
            ],
            'attendees' => $attendees,
            'attendeesCount' => $attendeesCount,
            'isRegistered' => $isRegistered,
            'isAuthenticated' => $authUser !== null,
        ]);
    }

    /**
     * @return array{filters: array{status: mixed, from: mixed, to: mixed, location_city: mixed}, statuses: list<string>, cities: Collection<int, string>, dateBounds: array{min: string, max: string}|null}
     */
    private function sharedListingProps(Request $request): array
    {
        return [
            'filters' => [
                'status' => $request->status,
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'location_city' => $request->input('location_city'),
            ],
            'statuses' => ['draft', 'published', 'cancelled', 'sold_out'],
            'cities' => City::orderBy('name')->pluck('name'),
            'dateBounds' => $this->eventDateBounds(),
        ];
    }

    /**
     * Returns the min/max event date range from the dataset, or null if no events exist.
     *
     * @return array{min: string, max: string}|null
     */
    private function eventDateBounds(): ?array
    {
        /** @var object{min_ts: string|null, max_ts: string|null}|null $bounds */
        $bounds = DB::selectOne('SELECT MIN(created_time) as min_ts, MAX(created_time) as max_ts FROM events');

        if ($bounds === null || $bounds->min_ts === null) {
            return null;
        }

        return [
            'min' => gmdate('Y-m-d', (int) $bounds->min_ts),
            'max' => gmdate('Y-m-d', (int) $bounds->max_ts),
        ];
    }

    /**
     * @return array{0: LengthAwarePaginator<int, Event>, 1: array{ms: int, bytes: int}}
     */
    private function loadListing(Request $request): array
    {
        $start = microtime(true);

        /** @var TimezoneService $tzService */
        $tzService = app(TimezoneService::class);

        $locationCity = $request->input('location_city');

        // Resolve city timezone for event-local date filtering when a city is selected.
        $cityTimezone = null;
        if ($locationCity) {
            $cityTimezone = City::where('name', $locationCity)->value('timezone');
        }

        $query = Event::with(['user', 'city', 'coverImage'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($locationCity, fn ($q, $c) => $q->where('location_city', $c));

        // Apply `from` date filter: convert local date to UTC range boundary.
        $from = $request->input('from');
        if ($from) {
            [$fromStart] = $tzService->localDateToUtcRange($from, $cityTimezone);
            $query->where('created_time', '>=', $fromStart);
        }

        // Apply `to` date filter: convert local date to UTC range boundary.
        $to = $request->input('to');
        if ($to) {
            [, $toEnd] = $tzService->localDateToUtcRange($to, $cityTimezone);
            $query->where('created_time', '<=', $toEnd);
        }

        $events = $query
            ->orderByDesc('created_time')
            ->paginate(50)
            ->withQueryString();

        $stats = [
            'ms' => (int) round((microtime(true) - $start) * 1000),
            'bytes' => strlen((string) json_encode($events->items())),
        ];

        return [$events, $stats];
    }
}
