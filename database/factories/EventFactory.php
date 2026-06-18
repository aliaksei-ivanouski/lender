<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['concert', 'conference', 'meetup', 'workshop', 'festival', 'sports', 'networking', 'exhibition']);
        $lat = fake()->latitude();
        $lng = fake()->longitude();
        $startsAt = fake()->numberBetween(strtotime('-1 year'), strtotime('+1 year'));

        return [
            'user_id' => User::factory(),
            'type' => $type,
            'status' => fake()->randomElement(['draft', 'published', 'cancelled', 'sold_out']),
            'created_time' => $startsAt,
            'latitude' => $lat,
            'longitude' => $lng,
            'payload' => [
                'name' => ucwords(fake()->words(3, true)),
                'category' => $type,
                'venue' => ['name' => fake()->company(), 'capacity' => fake()->numberBetween(20, 50000)],
                'location' => ['lat' => $lat, 'lng' => $lng],
                'schedule' => ['starts_at' => $startsAt, 'ends_at' => $startsAt + 7200],
                'pricing' => ['currency' => 'USD', 'min_price' => fake()->randomFloat(2, 0, 250)],
            ],
        ];
    }
}
