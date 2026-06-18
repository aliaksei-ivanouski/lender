<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventImage>
 */
class EventImageFactory extends Factory
{
    protected $model = EventImage::class;

    public function definition(): array
    {
        $index = $this->faker->numberBetween(1, 8);

        return [
            'event_id' => Event::factory(),
            'path' => sprintf('event-images/placeholder-%02d.jpg', $index),
            'sort_order' => 0,
            'alt' => 'Event image '.$index,
        ];
    }
}
