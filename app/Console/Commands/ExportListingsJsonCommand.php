<?php

namespace App\Console\Commands;

use App\Models\Listing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('app:export-listings-json {--path=database/seeders/data/listings.json} {--limit=300}')]
#[Description('Export listings from local database into JSON')]
class ExportListingsJsonCommand extends Command
{
    public function handle(): int
    {
        $relativePath = trim((string) $this->option('path'));
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : 300;
        $absolutePath = base_path($relativePath);

        $listings = Listing::query()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (Listing $listing): array {
                return [
                    'external_id' => $listing->external_id,
                    'url' => $listing->url,
                    'title' => $listing->title,
                    'description' => $listing->description,
                    'images' => is_array($listing->images) ? array_values($listing->images) : [],
                    'price' => $listing->price,
                    'size' => $listing->size,
                    'rooms' => $listing->rooms,
                    'bathrooms' => $listing->bathrooms,
                    'neighborhood' => $listing->neighborhood,
                    'type' => $listing->type,
                    'state' => $listing->state,
                    'characteristics' => is_array($listing->characteristics) ? array_values($listing->characteristics) : [],
                    'surreal_score' => $listing->surreal_score,
                    'surreal_reason' => $listing->surreal_reason,
                    'created_at' => $listing->created_at?->toDateTimeString(),
                    'updated_at' => $listing->updated_at?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put(
            $absolutePath,
            json_encode(
                [
                    'exported_at' => now()->toIso8601String(),
                    'count' => count($listings),
                    'listings' => $listings,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );

        $this->info('Exported '.count($listings).' listings to '.$relativePath);

        return self::SUCCESS;
    }
}
