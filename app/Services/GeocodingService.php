<?php

namespace App\Services;

use App\Support\CityAnchor;

final class GeocodingService
{
    /**
     * Returns the nearest CityAnchor to the given coordinates using squared
     * Euclidean distance over the static anchor list. No sqrt is needed since
     * we only compare relative distances.
     */
    public function nearestAnchor(float $lat, float $lng): CityAnchor
    {
        $anchors = CityAnchor::all();
        $best = $anchors[0];
        $bestDist = PHP_FLOAT_MAX;

        foreach ($anchors as $anchor) {
            $d = ($lat - $anchor->lat) ** 2 + ($lng - $anchor->lng) ** 2;
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = $anchor;
            }
        }

        return $best;
    }

    /**
     * Returns the bare city name (e.g. "New York") for storing in location_city.
     */
    public function cityName(float $lat, float $lng): string
    {
        return $this->nearestAnchor($lat, $lng)->city;
    }
}
