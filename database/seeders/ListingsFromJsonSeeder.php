<?php

namespace Database\Seeders;

use App\Models\Listing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class ListingsFromJsonSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('database/seeders/data/listings.json');

        if (! File::exists($path)) {
            $this->command?->warn('Listings JSON file not found at database/seeders/data/listings.json');

            return;
        }

        $decoded = json_decode((string) File::get($path), true);
        $rows = is_array($decoded['listings'] ?? null) ? $decoded['listings'] : [];

        if (count($rows) === 0) {
            $this->command?->warn('No listings found in JSON payload.');

            return;
        }

        $listings = collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row): array {
                $url = isset($row['url']) && is_string($row['url']) ? trim($row['url']) : null;

                $images = is_array($row['images'] ?? null) ? array_values($row['images']) : [];
                $characteristics = is_array($row['characteristics'] ?? null) ? array_values($row['characteristics']) : [];

                $externalId = isset($row['external_id']) && is_string($row['external_id']) && trim($row['external_id']) !== ''
                    ? trim($row['external_id'])
                    : ($url !== null && $url !== '' ? md5($url) : null);

                return [
                    'external_id' => $externalId,
                    'url' => $url,
                    'title' => isset($row['title']) && is_string($row['title']) ? trim($row['title']) : null,
                    'description' => isset($row['description']) && is_string($row['description']) ? trim($row['description']) : null,

                    // IMPORTANT: upsert payload must contain JSON strings for json columns
                    'images' => json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
                    'characteristics' => json_encode($characteristics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',

                    // Numeric casting
                    'price' => is_numeric($row['price'] ?? null) ? (int) $row['price'] : null,
                    'size' => is_numeric($row['size'] ?? null) ? (int) $row['size'] : null,
                    'rooms' => is_numeric($row['rooms'] ?? null) ? (int) $row['rooms'] : null,
                    'bathrooms' => is_numeric($row['bathrooms'] ?? null) ? (int) $row['bathrooms'] : null,
                    'surreal_score' => is_numeric($row['surreal_score'] ?? null) ? (float) $row['surreal_score'] : null,

                    'neighborhood' => isset($row['neighborhood']) && is_string($row['neighborhood']) ? trim($row['neighborhood']) : null,
                    'type' => isset($row['type']) && is_string($row['type']) ? trim($row['type']) : null,
                    'state' => isset($row['state']) && is_string($row['state']) ? trim($row['state']) : null,
                    'surreal_reason' => isset($row['surreal_reason']) && is_string($row['surreal_reason']) ? trim($row['surreal_reason']) : null,

                    'created_at' => isset($row['created_at']) && is_string($row['created_at'])
                        ? $row['created_at']
                        : now()->toDateTimeString(),
                    'updated_at' => isset($row['updated_at']) && is_string($row['updated_at'])
                        ? $row['updated_at']
                        : now()->toDateTimeString(),
                ];
            })
            ->filter(fn (array $row) => is_string($row['external_id']) && $row['external_id'] !== '')
            ->values()
            ->all();

        if (count($listings) === 0) {
            $this->command?->warn('No valid listings with external_id could be imported.');

            return;
        }

        $chunks = array_chunk($listings, 50);
        $imported = 0;

        foreach ($chunks as $index => $chunk) {
            try {
                Listing::query()->upsert(
                    $chunk,
                    ['external_id'],
                    [
                        'url',
                        'title',
                        'description',
                        'images',
                        'price',
                        'size',
                        'rooms',
                        'bathrooms',
                        'neighborhood',
                        'type',
                        'state',
                        'characteristics',
                        'surreal_score',
                        'surreal_reason',
                        'created_at',
                        'updated_at',
                    ]
                );

                $imported += count($chunk);
                $this->command?->info('Imported chunk '.($index + 1).'/'.count($chunks).' ('.count($chunk).' rows).');
            } catch (\Throwable $e) {
                Log::error('Listings JSON seeder chunk failed', [
                    'chunk_index' => $index + 1,
                    'chunk_size' => count($chunk),
                    'first_external_id' => $chunk[0]['external_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                $this->command?->error('Chunk '.($index + 1).' failed: '.$e->getMessage());

                throw $e;
            }
        }

        $this->command?->info('Imported '.$imported.' listings from JSON.');
    }
}