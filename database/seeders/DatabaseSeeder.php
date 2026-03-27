<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $jsonPath = base_path('database/seeders/data/listings.json');

        if (File::exists($jsonPath)) { // If the JSON file exists, seed the listings from the JSON file
            $this->call(ListingsFromJsonSeeder::class);

            return;
        }

        User::factory()->create([ // If not, creates a test user
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
