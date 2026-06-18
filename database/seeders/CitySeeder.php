<?php

namespace Database\Seeders;

use App\Models\City;
use App\Support\CityAnchor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        if (City::query()->exists()) {
            return;
        }

        $now = now()->toDateTimeString();

        $rows = array_map(
            static fn (CityAnchor $anchor): array => [
                'name' => $anchor->city,
                'region' => $anchor->region,
                'country' => $anchor->country,
                'latitude' => $anchor->lat,
                'longitude' => $anchor->lng,
                'timezone' => $anchor->ianaTimezone,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            CityAnchor::all(),
        );

        DB::table('cities')->insert($rows);
    }
}
