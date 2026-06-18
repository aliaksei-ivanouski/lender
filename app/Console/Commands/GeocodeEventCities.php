<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Event;
use Illuminate\Console\Command;

/**
 * One-time ETL backfill command that resolves the nearest city for every
 * event whose `location_city` column is NULL and writes the city name back.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHY THIS COMMAND LOADS ALL CITIES ONCE INTO MEMORY (in-memory argmin path)
 * ──────────────────────────────────────────────────────────────────────────
 * The architecture defines two geocoding paths:
 *
 *   1. REQUEST-TIME (runtime): `DatabaseReverseGeocoder` — uses a bbox SQL
 *      query against the `cities` table + a 24 h Laravel cache keyed on
 *      coordinates rounded to 2 decimal places. Correct for individual
 *      per-request lookups; cache warms organically from real traffic.
 *
 *   2. BACKFILL ETL (this command): processes up to 1.25 M events in one
 *      pass. EventSeeder jitters each event within ±0.5° of its anchor, so
 *      ~75 anchors × many events = a wide spread of jittered coordinates.
 *      Rounding to 2 decimal places (~1 km grid) does NOT collapse 1.25 M
 *      jittered points to ~75 distinct keys; it produces thousands of unique
 *      cache keys, so per-event `DatabaseReverseGeocoder::reverse()` calls
 *      would mostly miss the cold cache and issue up to 1.25 M individual DB
 *      queries. An in-memory argmin over ~75 City rows is O(75) per event and
 *      trivially fast — far cheaper than any DB/cache round-trip at this scale.
 *
 * DELIBERATE BOUNDARY: this "load-all-cities once" optimization is ONLY used
 * here, in this explicit ETL context. The runtime `ReverseGeocoder` port is
 * unchanged and never loads the full table. The ~75 row cardinality is small
 * and stable (seeded from CityAnchor), so a one-time full load is safe and
 * bounded. If the `cities` table were to grow to tens of thousands of rows,
 * this command would need to be revised to use the chunked DB path.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * GROUPED BULK UPDATE STRATEGY
 * ──────────────────────────────────────────────────────────────────────────
 * After resolving the nearest city for each event in a chunk, results are
 * grouped by city name. One `UPDATE … WHERE id IN (…)` is issued per unique
 * city per chunk (~75 cities → ~75 UPDATE statements per 4000-event chunk
 * instead of 4000 individual updates). This reduces DB round-trips by ~98 %
 * and keeps SQLite lock windows short.
 *
 * IDEMPOTENCY: only events with `location_city IS NULL` are selected, so
 * re-running the command is always a safe no-op.
 */
class GeocodeEventCities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:geocode-cities {--chunk=4000 : Rows per update batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill location_city on events that have coordinates but no city name (idempotent)';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        if ($chunk < 1) {
            $this->error('--chunk must be a positive integer.');

            return self::FAILURE;
        }

        // ── Step 1: load all cities once into memory ────────────────────────
        // ~75 rows — small, bounded, safe for a one-time ETL job.
        // See class docblock for full rationale.
        $cities = City::all(['id', 'name', 'latitude', 'longitude']);

        if ($cities->isEmpty()) {
            $this->error('No cities found in the database. Run CitySeeder first.');

            return self::FAILURE;
        }

        // Pre-build a plain PHP array for the inner loop (avoids Eloquent
        // property lookup overhead in the tight O(75) argmin loop).
        /** @var list<array{name: string, lat: float, lng: float}> $cityIndex */
        $cityIndex = $cities->map(fn (City $c) => [
            'name' => $c->name,
            'lat' => (float) $c->latitude,
            'lng' => (float) $c->longitude,
        ])->values()->all();

        // ── Step 2: count pending events for progress reporting ─────────────
        $total = Event::whereNull('location_city')->count();

        if ($total === 0) {
            $this->info('No events with NULL location_city — nothing to do (idempotent).');

            return self::SUCCESS;
        }

        $this->info("Geocoding {$total} events in chunks of {$chunk}…");

        $processed = 0;

        // ── Step 3: chunk through events with NULL location_city ────────────
        Event::query()
            ->whereNull('location_city')
            ->select(['id', 'latitude', 'longitude'])
            ->chunkById($chunk, function ($events) use ($cityIndex, &$processed, $total): void {
                /** @var array<string, list<string>> $grouped  city-name → list of event UUIDs */
                $grouped = [];

                foreach ($events as $event) {
                    // Skip events with missing coordinates (defensive guard).
                    if ($event->latitude === null || $event->longitude === null) {
                        continue;
                    }

                    $cityName = $this->nearestCityName(
                        (float) $event->latitude,
                        (float) $event->longitude,
                        $cityIndex,
                    );

                    if ($cityName !== null) {
                        $grouped[$cityName][] = $event->id;
                    }
                }

                // ── Step 4: one bulk UPDATE per unique city per chunk ────────
                // ~75 cities → ~75 UPDATE statements per 4000-event chunk.
                foreach ($grouped as $cityName => $ids) {
                    Event::whereIn('id', $ids)->update(['location_city' => $cityName]);
                }

                $processed += $events->count();
                $this->output->writeln(
                    "  → {$processed} / {$total} processed"
                );
            });

        $this->info("Done. {$processed} events geocoded.");

        return self::SUCCESS;
    }

    /**
     * Returns the name of the nearest city by minimum squared Euclidean
     * distance — O(N) over the in-memory city index (N ≈ 75).
     *
     * Squared distance is sufficient for nearest-neighbour selection and
     * avoids the sqrt() call in the tight inner loop.
     *
     * @param  list<array{name: string, lat: float, lng: float}>  $cityIndex
     */
    private function nearestCityName(float $lat, float $lng, array $cityIndex): ?string
    {
        $bestName = null;
        $bestDist = PHP_FLOAT_MAX;

        foreach ($cityIndex as $city) {
            $dLat = $lat - $city['lat'];
            $dLng = $lng - $city['lng'];
            $dist = $dLat * $dLat + $dLng * $dLng;

            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestName = $city['name'];
            }
        }

        return $bestName;
    }
}
