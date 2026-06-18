<?php

namespace App\Services\Geocoding;

use App\Models\City;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DatabaseReverseGeocoder implements ReverseGeocoder
{
    private const CACHE_TTL = 86400;

    private const DELTAS = [5.0, 20.0, 90.0];

    public function reverse(float $lat, float $lng): ?City
    {
        $cacheKey = sprintf('geocoder.rev:%.2f.%.2f', round($lat, 2), round($lng, 2));

        // Cache the City id (int) rather than the model object, because
        // config/cache.php has serializable_classes = false which prevents
        // caching Eloquent instances. A null result (no city found) is stored
        // as the sentinel integer 0 so Cache::remember can distinguish a
        // genuine cache miss from a stored null.
        /** @var int $cachedId */
        $cachedId = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($lat, $lng): int {
            $city = $this->queryNearest($lat, $lng);

            return $city !== null ? (int) $city->id : 0;
        });

        if ($cachedId === 0) {
            return null;
        }

        return City::find($cachedId);
    }

    private function queryNearest(float $lat, float $lng): ?City
    {
        foreach (self::DELTAS as $delta) {
            $row = DB::table('cities')
                ->whereBetween('latitude', [$lat - $delta, $lat + $delta])
                ->whereBetween('longitude', [$lng - $delta, $lng + $delta])
                ->orderByRaw(
                    '((latitude - ?) * (latitude - ?) + (longitude - ?) * (longitude - ?))',
                    [$lat, $lat, $lng, $lng],
                )
                ->limit(1)
                ->first();

            if ($row !== null) {
                // Hydrate a City model from the stdClass row.
                /** @var City $city */
                $city = City::newModelInstance();
                $city->forceFill((array) $row);
                $city->exists = true;

                return $city;
            }
        }

        return null;
    }
}
