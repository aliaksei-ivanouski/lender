<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EventSeeder extends Seeder
{
    /**
     * Approximate encoded size of a single payload, in bytes. Dial this to
     * change the on-disk footprint of the seeded dataset.
     */
    public const PAYLOAD_AVG_BYTES = 1500;

    public const NUM_USERS = 3000;

    private const CHUNK = 4000;

    /** Event categories (stored in the `type` column). */
    private const TYPES = ['concert', 'conference', 'meetup', 'workshop', 'festival', 'sports', 'networking', 'exhibition'];

    private const STATUSES = ['draft', 'published', 'cancelled', 'sold_out'];

    private const NAME_ADJECTIVES = ['Annual', 'Global', 'Summer', 'Winter', 'Underground', 'Open', 'International', 'Live', 'Midnight', 'Sunset', 'Urban', 'Indie', 'Grand', 'Pop-up', 'Virtual'];

    private const NAME_THEMES = ['Synthwave', 'Founders', 'Jazz', 'Tech', 'Food & Wine', 'Yoga', 'Startup', 'Design', 'Climate', 'Gaming', 'Film', 'Book', 'Marathon', 'Comedy', 'Art'];

    private const NAME_FORMATS = ['Festival', 'Meetup', 'Conference', 'Summit', 'Workshop', 'Expo', 'Showcase', 'Gala', 'Jam', 'Retreat', 'Fair', 'Night', 'Tour', 'Symposium', 'Block Party'];

    /**
     * Anchor coordinates [lat, lng] for major cities across the US, Canada,
     * Mexico and Europe, plus a few global hubs. Each row is jittered around
     * one of these anchors.
     */
    private const CITY_ANCHORS = [
        // United States
        [40.7128, -74.0060], [34.0522, -118.2437], [41.8781, -87.6298], [29.7604, -95.3698],
        [33.4484, -112.0740], [39.9526, -75.1652], [29.4241, -98.4936], [32.7157, -117.1611],
        [32.7767, -96.7970], [37.3382, -121.8863], [30.2672, -97.7431], [37.7749, -122.4194],
        [47.6062, -122.3321], [39.7392, -104.9903], [42.3601, -71.0589], [36.1699, -115.1398],
        [25.7617, -80.1918], [33.7490, -84.3880], [38.9072, -77.0369], [36.1627, -86.7816],
        [45.5152, -122.6784], [29.9511, -90.0715],
        // Canada
        [43.6532, -79.3832], [45.5019, -73.5674], [49.2827, -123.1207], [51.0447, -114.0719],
        [45.4215, -75.6972], [53.5461, -113.4938], [46.8139, -71.2080], [49.8951, -97.1384],
        // Mexico
        [19.4326, -99.1332], [20.6597, -103.3496], [25.6866, -100.3161], [19.0414, -98.2063],
        [32.5149, -117.0382], [21.1619, -86.8515], [20.9674, -89.5926],
        // Europe
        [51.5074, -0.1278], [48.8566, 2.3522], [52.5200, 13.4050], [40.4168, -3.7038],
        [41.9028, 12.4964], [52.3676, 4.9041], [41.3851, 2.1734], [48.1351, 11.5820],
        [45.4642, 9.1900], [48.2082, 16.3738], [50.0755, 14.4378], [38.7223, -9.1393],
        [53.3498, -6.2603], [55.6761, 12.5683], [59.3293, 18.0686], [59.9139, 10.7522],
        [60.1699, 24.9384], [50.8503, 4.3517], [47.3769, 8.5417], [52.2297, 21.0122],
        [47.4979, 19.0402], [37.9838, 23.7275], [45.7640, 4.8357], [53.5511, 9.9937],
        [53.4808, -2.2426], [55.9533, -3.1883], [50.1109, 8.6821], [50.0647, 19.9450],
        [41.1579, -8.6291], [40.8518, 14.2681],
        // A few global hubs
        [35.6762, 139.6503], [37.5665, 126.9780], [1.3521, 103.8198], [-33.8688, 151.2093],
        [-37.8136, 144.9631], [25.2048, 55.2708], [-23.5505, -46.6333], [-34.6037, -58.3816],
    ];

    public function run(): void
    {
        $rows = (int) config('seeding.rows', 1_250_000);

        $this->command->info("Seeding {$rows} events...");

        $start = microtime(true);

        $this->withSeedingPragmas(function () use ($rows) {
            $this->ensureUsers();
            $this->insertEvents($rows);
        });

        $elapsed = round(microtime(true) - $start, 1);
        $rate = $elapsed > 0 ? round($rows / $elapsed) : $rows;
        $this->command->info("Done. {$rows} events in {$elapsed}s ({$rate} rows/s).");
    }

    /**
     * Bulk-insert $count event rows using cheap, template-driven payloads.
     * Reused by the perf tests to top up the dataset to a target size.
     */
    public function insertEvents(int $count): void
    {
        $this->ensureUsers();

        DB::connection()->disableQueryLog();

        $template = $this->payloadTemplate();
        $now = date('Y-m-d H:i:s');
        $userMax = self::NUM_USERS;

        $year = 365 * 24 * 60 * 60;
        $now_ts = time();
        // Event start times span roughly one year in the past to one year out.
        $startTime = $now_ts - $year;
        $endTime = $now_ts + $year;

        $typeWeights = $this->cumulativeWeights([20, 14, 22, 12, 12, 8, 8, 4]);
        $statusWeights = $this->cumulativeWeights([12, 70, 8, 10]);
        $anchorCount = count(self::CITY_ANCHORS);

        $remaining = $count;
        $done = 0;

        while ($remaining > 0) {
            $batchSize = min(self::CHUNK, $remaining);
            $batch = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $type = self::TYPES[$this->pick($typeWeights)];
                $status = self::STATUSES[$this->pick($statusWeights)];
                $startsAt = mt_rand($startTime, $endTime);
                $endsAt = $startsAt + mt_rand(3600, 3 * 24 * 3600);

                $anchor = self::CITY_ANCHORS[mt_rand(0, $anchorCount - 1)];
                $latitude = round($anchor[0] + (mt_rand(-500, 500) / 1000), 7);
                $longitude = round($anchor[1] + (mt_rand(-500, 500) / 1000), 7);

                $name = self::NAME_ADJECTIVES[array_rand(self::NAME_ADJECTIVES)]
                    .' '.self::NAME_THEMES[array_rand(self::NAME_THEMES)]
                    .' '.self::NAME_FORMATS[array_rand(self::NAME_FORMATS)];

                $payload = strtr($template, [
                    '{{NAME}}' => $this->escape($name),
                    '{{CATEGORY}}' => $type,
                    '{{ORGANIZER}}' => 'Organizer '.mt_rand(1, 9999),
                    '{{VENUE}}' => $this->escape($this->venueName()),
                    '{{LAT}}' => (string) $latitude,
                    '{{LNG}}' => (string) $longitude,
                    '{{STARTS}}' => (string) $startsAt,
                    '{{ENDS}}' => (string) $endsAt,
                    '{{CAPACITY}}' => (string) mt_rand(20, 50000),
                    '{{PRICE}}' => (string) (mt_rand(0, 25000) / 100),
                ]);

                $batch[] = [
                    'id' => $this->uuidv4(),
                    'user_id' => mt_rand(1, $userMax),
                    'type' => $type,
                    'status' => $status,
                    'created_time' => $startsAt,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'payload' => $payload,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::transaction(function () use ($batch) {
                DB::table('events')->insert($batch);
            });

            $done += $batchSize;
            $remaining -= $batchSize;

            if ($done % (self::CHUNK * 25) === 0 || $remaining === 0) {
                $this->command->getOutput()->writeln("  inserted {$done}/{$count}");
            }
        }
    }

    private function ensureUsers(): void
    {
        $existing = DB::table('users')->count();
        if ($existing >= self::NUM_USERS) {
            return;
        }

        $password = Hash::make('password');
        $now = date('Y-m-d H:i:s');

        $remaining = self::NUM_USERS - $existing;
        $offset = $existing;

        while ($remaining > 0) {
            $batchSize = min(1000, $remaining);
            $batch = [];

            for ($i = 0; $i < $batchSize; $i++) {
                $n = $offset + $i + 1;
                $batch[] = [
                    'name' => "User {$n}",
                    'email' => "user{$n}@example.test",
                    'email_verified_at' => $now,
                    'password' => $password,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('users')->insert($batch);
            $offset += $batchSize;
            $remaining -= $batchSize;
        }
    }

    /**
     * Build a ~PAYLOAD_AVG_BYTES payload string once, with placeholder tokens
     * that are cheaply substituted per row.
     */
    private function payloadTemplate(): string
    {
        $payload = [
            'name' => '{{NAME}}',
            'category' => '{{CATEGORY}}',
            'description' => 'Join us for {{NAME}} — a {{CATEGORY}} you won\'t want to miss.',
            'organizer' => [
                'name' => '{{ORGANIZER}}',
                'verified' => true,
            ],
            'venue' => [
                'name' => '{{VENUE}}',
                'capacity' => '{{CAPACITY}}',
            ],
            'location' => [
                'lat' => '{{LAT}}',
                'lng' => '{{LNG}}',
            ],
            'schedule' => [
                'starts_at' => '{{STARTS}}',
                'ends_at' => '{{ENDS}}',
            ],
            'pricing' => [
                'currency' => 'USD',
                'min_price' => '{{PRICE}}',
            ],
            'tags' => ['live', 'in-person', 'featured', 'all-ages'],
            'notes' => '',
        ];

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $pad = self::PAYLOAD_AVG_BYTES - strlen($encoded);
        if ($pad > 0) {
            $payload['notes'] = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', (int) ceil($pad / 56));
            $payload['notes'] = substr($payload['notes'], 0, $pad);
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    private function venueName(): string
    {
        $a = ['The Grand', 'Riverside', 'Downtown', 'Skyline', 'Harbor', 'Old Town', 'Central', 'Sunset'];
        $b = ['Hall', 'Arena', 'Pavilion', 'Gardens', 'Warehouse', 'Theatre', 'Rooftop', 'Stadium'];

        return $a[array_rand($a)].' '.$b[array_rand($b)];
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function uuidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private function cumulativeWeights(array $weights): array
    {
        $cumulative = [];
        $sum = 0;
        foreach ($weights as $w) {
            $sum += $w;
            $cumulative[] = $sum;
        }

        return $cumulative;
    }

    /** @param array<int, int> $cumulative */
    private function pick(array $cumulative): int
    {
        if ($cumulative === []) {
            return 0;
        }

        $total = end($cumulative);
        $roll = mt_rand(1, $total);
        foreach ($cumulative as $index => $threshold) {
            if ($roll <= $threshold) {
                return $index;
            }
        }

        return 0;
    }

    private function withSeedingPragmas(callable $callback): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            $callback();

            return;
        }

        DB::statement('PRAGMA journal_mode = MEMORY');
        DB::statement('PRAGMA synchronous = OFF');
        DB::statement('PRAGMA temp_store = MEMORY');
        DB::statement('PRAGMA cache_size = -64000');

        try {
            $callback();
        } finally {
            DB::statement('PRAGMA journal_mode = WAL');
            DB::statement('PRAGMA synchronous = NORMAL');
        }
    }
}
