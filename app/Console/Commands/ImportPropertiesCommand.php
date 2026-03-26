<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;
use OpenAI\Laravel\Facades\OpenAI;
use App\Models\Listing;
use Illuminate\Support\Str;

class ImportPropertiesCommand extends Command
{
    // Este es el nombre del comando que escribiremos en la terminal
    protected $signature = 'app:import-properties';
    
    protected $description = 'Extracts properties from pisos.com and uses AI to normalize the data';

    public function handle()
    {
        $totalPages = 10;
        $placeholderImage = 'https://placehold.co/1200x800?text=No+Image';

        $extractText = static function (Crawler $scope, string $selector): ?string {
            if ($scope->filter($selector)->count() === 0) {
                return null;
            }

            $value = Str::of($scope->filter($selector)->first()->text(''))->squish()->toString();

            return $value !== '' ? $value : null;
        };

        $canonicalizeUrl = static function (string $url): string {
            $parts = parse_url($url);

            if ($parts === false) {
                return rtrim($url, '/');
            }

            $scheme = $parts['scheme'] ?? 'https';
            $host = strtolower($parts['host'] ?? 'www.pisos.com');
            $path = '/' . ltrim($parts['path'] ?? '/', '/');

            return rtrim("{$scheme}://{$host}{$path}", '/');
        };

        $toAbsoluteAssetUrl = static function (?string $url): ?string {
            if (!is_string($url) || trim($url) === '') {
                return null;
            }

            $normalized = trim($url);
            if (str_starts_with($normalized, 'data:')) {
                return null;
            }

            if (Str::startsWith($normalized, '//')) {
                return 'https:' . $normalized;
            }

            if (Str::startsWith($normalized, '/')) {
                return 'https://www.pisos.com' . $normalized;
            }

            return $normalized;
        };

        for ($page = 1; $page <= $totalPages; $page++) {
            $this->info('--- Processing Page ' . $page . '/' . $totalPages . ' ---');

            $url = "https://www.pisos.com/venta/pisos-madrid/{$page}/";
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            ])->get($url);

            if (!$response->successful()) {
                $this->warn('Skipping page ' . $page . '. HTTP code: ' . $response->status());
                continue;
            }

            $crawler = new Crawler($response->body());
            $ads = $crawler->filter('div.ad-preview')->slice(0, 30);

            if ($ads->count() === 0) {
                $this->warn('No ads found on page ' . $page . '. Stopping pagination.');
                break;
            }

            $this->info('Processing ' . $ads->count() . ' ads with OpenAI (gpt-4o-mini)...');
            $adsCount = $ads->count();

            $ads->each(function (Crawler $node, $i) use ($adsCount, $canonicalizeUrl, $extractText, $page, $placeholderImage, $toAbsoluteAssetUrl, $totalPages) {
                $infoNode = $node->filter('div.ad-preview__info');
                $infoText = $infoNode->count() > 0
                    ? $infoNode->first()->text('')
                    : $node->text('');
                $rawText = Str::of($infoText)->squish()->toString();

                $title = $extractText($node, 'a.ad-preview__title');
                $price = $extractText($node, 'span.ad-preview__price');
                $neighborhood = $extractText($node, 'p.ad-preview__subtitle');
                $features = $extractText($node, 'p.ad-preview__char');
                $description = $extractText($node, 'p.ad-preview__description');

                $imageSelectors = [
                    '.carousel__main-photo-mosaic img',
                    '.carousel__secondary-photo-as-img img',
                    '.carousel__mosaic-item img',
                ];

                $images = collect($imageSelectors)
                    ->flatMap(function (string $selector) use ($node, $toAbsoluteAssetUrl) {
                        if ($node->filter($selector)->count() === 0) {
                            return [];
                        }

                        return collect($node->filter($selector)->each(function (Crawler $imgNode) use ($toAbsoluteAssetUrl) {
                            $candidate = $imgNode->attr('src')
                                ?? $imgNode->attr('data-src')
                                ?? $imgNode->attr('data-original')
                                ?? '';

                            if (($candidate === '' || str_starts_with($candidate, 'data:')) && is_string($imgNode->attr('srcset'))) {
                                $srcSetFirst = trim(explode(',', $imgNode->attr('srcset'))[0] ?? '');
                                $candidate = trim(explode(' ', $srcSetFirst)[0] ?? '');
                            }

                            return $toAbsoluteAssetUrl($candidate);
                        }))
                            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
                            ->values()
                            ->all();
                    })
                    ->unique()
                    ->values()
                    ->all();

                if (count($images) === 0) {
                    $images[] = $placeholderImage;
                }

                while (count($images) < 5) {
                    $images[] = $images[0] ?? $placeholderImage;
                }

                $images = array_slice($images, 0, 5);

                $relativeUrl = $node->filter('a.ad-preview__title')->count() > 0
                    ? $node->filter('a.ad-preview__title')->first()->attr('href')
                    : null;
                $listingUrl = is_string($relativeUrl) && $relativeUrl !== ''
                    ? (Str::startsWith($relativeUrl, 'http') ? $relativeUrl : 'https://www.pisos.com' . $relativeUrl)
                    : 'https://www.pisos.com/venta/pisos-madrid/';

                $canonicalListingUrl = $canonicalizeUrl($listingUrl);
                $externalId = md5($canonicalListingUrl);

                $displayTitle = $title ?: 'Untitled';
                $displayPrice = $price ?: 'N/A';
                $progressPrefix = '[Page ' . $page . '/' . $totalPages . '] Ad ' . ($i + 1) . '/' . $adsCount . ': ' . $displayTitle . ' - ' . $displayPrice;
                $this->line($progressPrefix);

                if (Listing::query()->where('external_id', $externalId)->exists()) {
                    $this->line('   Skipped (already imported): ' . $canonicalListingUrl);
                    return;
                }

                try {
                    $structuredInput = json_encode([
                        'title' => $title,
                        'price' => $price,
                        'subtitle' => $neighborhood,
                        'features' => $features,
                        'description' => $description,
                    ], JSON_UNESCAPED_UNICODE);

                    sleep(1);

                    $aiResponse = OpenAI::chat()->create([
                        'model' => 'gpt-4o-mini',
                        'response_format' => ['type' => 'json_object'],
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => "You are an expert real estate agent in Madrid. Your goal is to NORMALIZE the data and return it as a VALID JSON object.
- title: string (the original Spanish title)
- description: string (IMPORTANT: Write a BRAND NEW, attractive summary in high-quality ENGLISH, max 4 lines. Do NOT just translate. Read the provided text and features, understand the essence, and write cohesive English copy highlighting the best parts, e.g., 'Charming renovated flat near Retiro Park featuring...').
- price: integer (numbers only)
- size: integer (square meters)
- rooms: integer
- bathrooms: integer
- neighborhood: string (ONLY the name, e.g., 'Salamanca' instead of 'Distrito Salamanca')
- type: string (Flat, House, Penthouse, Studio)
- state: string (New, Used, To renovate)
- characteristics: array of strings. This field is CRITICAL. You MUST scour BOTH the Spanish 'features' text and the full 'description' narrative for ANY conceptual amenities. Map the Spanish concepts to standardized ENGLISH keywords. Do not miss them. Examples of concepts to look for and convert to English:
  * pool (piscina)
  * elevator (ascensor)
  * ac (aire acondicionado, climatizado)
  * heating (calefacción, suelo radiante)
  * terrace (terraza)
  * balcony (balcón)
  * parking (garaje, plaza de garaje, cochera)
  * storage room (trastero)
  * built-in wardrobes (armarios empotrados)
  * equipped kitchen (cocina amueblada, equipada)
  * renovated (reformado, a estrenar)
  * bright (luminoso)
  * gym (gimnasio, gym)
  * paddle court (pádel, pista de pádel)
  * garden (jardín, zonas ajardinadas)
  * security (conserje, portero, seguridad 24h)",
                            ],
                            [
                                'role' => 'user',
                                'content' => "Extract listing data and generate an English description from this ad.
\n\nParsed fields:\n{$structuredInput}\n\nText block:\n{$rawText}",
                            ],
                        ],
                    ]);

                    $content = $aiResponse->choices[0]->message->content ?? '';
                    $jsonData = json_decode($content, true);

                    if (!is_array($jsonData)) {
                        throw new \RuntimeException('Model did not return a valid JSON object.');
                    }

                    $listingData = [
                        'external_id' => $externalId,
                        'url' => $canonicalListingUrl,
                        'title' => $jsonData['title'] ?? $title,
                        'description' => $jsonData['description'] ?? $description,
                        'price' => isset($jsonData['price']) ? (int) $jsonData['price'] : null,
                        'size' => isset($jsonData['size']) ? (int) $jsonData['size'] : null,
                        'rooms' => isset($jsonData['rooms']) ? (int) $jsonData['rooms'] : null,
                        'bathrooms' => isset($jsonData['bathrooms']) ? (int) $jsonData['bathrooms'] : null,
                        'type' => $jsonData['type'] ?? null,
                        'state' => $jsonData['state'] ?? null,
                        'city' => 'Madrid',
                        'neighborhood' => $jsonData['neighborhood'] ?? $neighborhood,
                        'images' => $images,
                        'characteristics' => is_array($jsonData['characteristics'] ?? null)
                            ? array_values($jsonData['characteristics'])
                            : [],
                    ];

                    $matchAttributes = ['external_id' => $externalId];
                    Listing::updateOrCreate($matchAttributes, $listingData);

                    $this->info("   Saved: " . ($listingData['title'] ?? 'Untitled') . ' - ' . ($listingData['price'] ?? 'N/A') . "€!");
                } catch (\Exception $e) {
                    $this->error('   Error with the AI: ' . $e->getMessage());
                }
            });
        }

        $this->info('Process completed! Review database.');
    }
}