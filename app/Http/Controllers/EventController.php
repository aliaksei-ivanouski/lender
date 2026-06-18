<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\City;
use App\Models\Event;
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
        $event->load('user');

        return Inertia::render('Events/Show', [
            'event' => $event,
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
