<?php

namespace App\Services\Geocoding;

use App\Models\City;

interface ReverseGeocoder
{
    /**
     * Returns the nearest City to the given coordinates, or null if no city
     * can be resolved (should not occur for EventSeeder-generated coordinates).
     */
    public function reverse(float $lat, float $lng): ?City;
}
