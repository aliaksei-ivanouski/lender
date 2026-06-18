<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Defaults to 1,250,000 events (≈2.5 GB; ~3s first listing load on a
        // laptop). Override with SEED_ROWS, e.g. SEED_ROWS=50000 php artisan db:seed
        $this->call(EventSeeder::class);

        // $this->call(EventImageSeeder::class); // wired in Wave 0-C
    }
}
