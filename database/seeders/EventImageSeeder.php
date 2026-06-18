<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EventImageSeeder extends Seeder
{
    private const CHUNK = 4000;

    private const PLACEHOLDER_COUNT = 8;

    public function run(): void
    {
        $this->copyPlaceholders();

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA journal_mode = MEMORY');
            DB::statement('PRAGMA synchronous = OFF');
            DB::statement('PRAGMA temp_store = MEMORY');
            DB::statement('PRAGMA cache_size = -64000');
        }

        try {
            $this->insertImages();
        } finally {
            if ($driver === 'sqlite') {
                DB::statement('PRAGMA journal_mode = WAL');
                DB::statement('PRAGMA synchronous = NORMAL');
            }
        }
    }

    private function copyPlaceholders(): void
    {
        Storage::disk('public')->makeDirectory('event-images');

        for ($n = 1; $n <= self::PLACEHOLDER_COUNT; $n++) {
            $filename = $n.'.png';
            $source = database_path('seeders/images/'.$filename);
            $destination = 'event-images/'.$filename;

            if (! Storage::disk('public')->exists($destination)) {
                $contents = file_get_contents($source);
                if ($contents !== false) {
                    Storage::disk('public')->put($destination, $contents);
                }
            }
        }

        $this->command->info('Copied 8 images to public disk.');
    }

    private function insertImages(): void
    {
        DB::connection()->disableQueryLog();

        $now = date('Y-m-d H:i:s');

        Event::query()->whereDoesntHave('images')->select('id')->chunkById(self::CHUNK, function ($events) use ($now) {
            $batch = [];

            foreach ($events as $event) {
                $i = crc32((string) $event->id) & 0x7FFFFFFF;

                $firstIndex = ($i % self::PLACEHOLDER_COUNT) + 1;
                $secondIndex = (($i + 1) % self::PLACEHOLDER_COUNT) + 1;

                $batch[] = [
                    'event_id' => $event->id,
                    'path' => 'event-images/'.$firstIndex.'.png',
                    'sort_order' => 0,
                    'alt' => 'Event image 1',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $batch[] = [
                    'event_id' => $event->id,
                    'path' => 'event-images/'.$secondIndex.'.png',
                    'sort_order' => 1,
                    'alt' => 'Event image 2',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('event_images')->insert($batch);
        });

        $this->command->info('EventImageSeeder complete.');
    }
}
