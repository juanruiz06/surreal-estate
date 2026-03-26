<?php

namespace App\Console\Commands;

use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use Symfony\Component\DomCrawler\Crawler;

class ImportPropertiesCommand extends Command
{
    // We will write this command in the terminal
    protected $signature = 'app:import-properties';

    protected $description = 'Extracts properties from pisos.com and uses AI to normalize the data';

    public function handle()
    {
        $totalPages = 10;
        $placeholderImage = 'https://placehold.co/1200x800?text=No+Image';

        $extractText = static function (Crawler $scope, string $selector): ?string { // Gets clean text from the selector or returns null
            if ($scope->filter($selector)->count() === 0) {
                return null;
            }

            $value = Str::of($scope->filter($selector)->first()->text(''))->squish()->toString();

            return $value !== '' ? $value : null;
        };

        $canonicalizeUrl = static function (string $url): string { // Removes query parameters from the url, trims slashes, forces lowercase in host
            $parts = parse_url($url);

            if ($parts === false) {
                return rtrim($url, '/');
            }

            $scheme = $parts['scheme'] ?? 'https';
            $host = strtolower($parts['host'] ?? 'www.pisos.com');
            $path = '/'.ltrim($parts['path'] ?? '/', '/');

            return rtrim("{$scheme}://{$host}{$path}", '/');
        };

        $toAbsoluteAssetUrl = static function (?string $url): ?string { // Cleans image urls to be absolute and valid
            if (! is_string($url) || trim($url) === '') {
                return null;
            }

            $normalized = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
            $normalized = str_replace('\\/', '/', $normalized);
            $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;
            $normalized = trim($normalized, "\"'");

            if (str_starts_with($normalized, 'data:')) {
                return null;
            }

            if (Str::startsWith($normalized, '//')) {
                return 'https:'.$normalized;
            }

            if (Str::startsWith($normalized, '/')) {
                return 'https://www.pisos.com'.$normalized;
            }

            return $normalized;
        };

        $isLogoLike = static function (?string $value): bool {
            if (! is_string($value) || trim($value) === '') {
                return false;
            }

            return str_contains(Str::lower($value), 'logo');
        };

        for ($page = 1; $page <= $totalPages; $page++) {
            $this->info('--- Processing Page '.$page.'/'.$totalPages.' ---');

            $url = "https://www.pisos.com/venta/pisos-madrid/{$page}/";
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            ])->get($url); // Gets the page content

            if (! $response->successful()) {
                $this->warn('Skipping page '.$page.'. HTTP code: '.$response->status());

                continue;
            }

            $crawler = new Crawler($response->body()); // Creates a crawler from the page content
            $ads = $crawler->filter('div.ad-preview')->slice(0, 30); // Gets the ads from the page

            if ($ads->count() === 0) {
                $this->warn('No ads found on page '.$page.'. Stopping pagination.');
                break;
            }

            $this->info('Processing '.$ads->count().' ads with OpenAI (gpt-4o-mini)...');
            $adsCount = $ads->count();

            $ads->each(function (Crawler $node, $i) use ($adsCount, $canonicalizeUrl, $extractText, $isLogoLike, $page, $placeholderImage, $toAbsoluteAssetUrl, $totalPages) {
                $infoNode = $node->filter('div.ad-preview__info');
                $infoText = $infoNode->count() > 0
                    ? $infoNode->first()->text('')
                    : $node->text('');
                $rawText = Str::of($infoText)->squish()->toString(); // Cleans the text from the info node

                $title = $extractText($node, 'a.ad-preview__title');
                $price = $extractText($node, 'span.ad-preview__price');
                $neighborhood = $extractText($node, 'p.ad-preview__subtitle');
                $features = $extractText($node, 'p.ad-preview__char');
                $description = $extractText($node, 'p.ad-preview__description');

                $imageSelectors = [ // gets pictures
                    '.carousel__container .carousel__main-photo-mosaic img',
                    '.carousel__container .carousel__secondary-photo-as-img img',
                    '.carousel__container .carousel__mosaic-item img',
                    '.carousel__container .carousel__slide img',
                    '.carousel__container picture img',
                    '.carousel__container picture source',
                    '.carousel__container img',
                ];

                $images = collect($imageSelectors)
                    ->flatMap(function (string $selector) use ($isLogoLike, $node, $toAbsoluteAssetUrl) {
                        if ($node->filter($selector)->count() === 0) {
                            return [];
                        }

                        return collect($node->filter($selector)->each(function (Crawler $imgNode) use ($isLogoLike, $toAbsoluteAssetUrl) {
                            $class = (string) ($imgNode->attr('class') ?? '');
                            $id = (string) ($imgNode->attr('id') ?? '');
                            $alt = (string) ($imgNode->attr('alt') ?? '');

                            if ($isLogoLike($class) || $isLogoLike($id) || $isLogoLike($alt)) {
                                return null;
                            }

                            $candidate = $imgNode->attr('data-src')
                                ?? $imgNode->attr('data-lazy')
                                ?? $imgNode->attr('data-lazy-src')
                                ?? '';

                            if (($candidate === '' || str_starts_with($candidate, 'data:')) && is_string($imgNode->attr('srcset'))) {
                                $srcSetFirst = trim(explode(',', $imgNode->attr('srcset'))[0] ?? '');
                                $candidate = trim(explode(' ', $srcSetFirst)[0] ?? '');
                            }

                            if ($candidate === '' || str_starts_with($candidate, 'data:')) {
                                $candidate = $imgNode->attr('src') ?? '';
                            }

                            if ($isLogoLike($candidate)) {
                                return null;
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

                $dataPhotosImages = collect($node->filter('[data-photos]')->each(fn (Crawler $dataNode) => $dataNode->attr('data-photos')))
                    ->filter(fn ($payload) => is_string($payload) && trim($payload) !== '')
                    ->flatMap(function (string $payload) use ($isLogoLike, $toAbsoluteAssetUrl) {
                        $decoded = html_entity_decode($payload, ENT_QUOTES | ENT_HTML5);
                        $decoded = str_replace('\\/', '/', $decoded);
                        $results = [];

                        $json = json_decode($decoded, true);
                        if (is_array($json)) {
                            array_walk_recursive($json, function ($value) use (&$results): void {
                                if (is_string($value)) {
                                    $results[] = $value;
                                }
                            });
                        }

                        preg_match_all('/(?:https?:)?\/\/[^\s"\'<>]+?\.(?:jpg|jpeg|png|webp|gif)(?:\?[^\s"\'<>]*)?/i', $decoded, $matches);
                        preg_match_all('/https:\/\/fotos\.itnm\.es\/[^\s"\'<>]+?\.jpg(?:\?[^\s"\'<>]*)?/i', $decoded, $itnmMatches);
                        $results = array_merge($results, $matches[0] ?? [], $itnmMatches[0] ?? []);

                        return collect($results)
                            ->map(fn ($url) => $toAbsoluteAssetUrl($url))
                            ->filter(fn ($url) => is_string($url) && ! $isLogoLike($url))
                            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
                            ->values()
                            ->all();
                    })
                    ->unique()
                    ->values()
                    ->all();

                $scriptImages = collect($node->filter('script')->each(fn (Crawler $scriptNode) => $scriptNode->text('')))
                    ->flatMap(function (string $scriptContent) use ($isLogoLike, $toAbsoluteAssetUrl) {
                        $normalizedScript = str_replace('\\/', '/', $scriptContent);
                        $results = [];

                        preg_match_all('/(?:https?:)?\/\/[^\s"\'<>]+?\.(?:jpg|jpeg|png|webp|gif)(?:\?[^\s"\'<>]*)?/i', $normalizedScript, $allImageMatches);
                        preg_match_all('/https:\/\/fotos\.itnm\.es\/[^\s"\'<>]+?\.jpg(?:\?[^\s"\'<>]*)?/i', $normalizedScript, $itnmMatches);
                        $results = array_merge($results, $allImageMatches[0] ?? [], $itnmMatches[0] ?? []);

                        return collect($results)
                            ->map(fn ($url) => $toAbsoluteAssetUrl($url))
                            ->filter(fn ($url) => is_string($url) && ! $isLogoLike($url))
                            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
                            ->values()
                            ->all();
                    })
                    ->unique()
                    ->values()
                    ->all();

                $containerHtmlImageCandidates = (function () use ($node): array {
                    try {
                        $containerHtml = $node->html('');
                    } catch (\Throwable) {
                        return [];
                    }

                    if (! is_string($containerHtml) || trim($containerHtml) === '') {
                        return [];
                    }

                    $normalizedHtml = html_entity_decode($containerHtml, ENT_QUOTES | ENT_HTML5);
                    $normalizedHtml = str_replace('\\/', '/', $normalizedHtml);
                    $results = [];

                    preg_match_all('/https:\/\/fotos\.itnm\.es\/[^\s"\'<>]+?\.jpg(?:\?[^\s"\'<>]*)?/i', $normalizedHtml, $itnmMatches);
                    preg_match_all('/(?:https?:)?\/\/[^\s"\'<>]+?\.(?:jpg|jpeg|png|webp|gif)(?:\?[^\s"\'<>]*)?/i', $normalizedHtml, $allImageMatches);
                    $results = array_merge($results, $itnmMatches[0] ?? [], $allImageMatches[0] ?? []);

                    return $results;
                })();

                $containerHtmlImages = collect($containerHtmlImageCandidates)
                    ->map(fn ($url) => $toAbsoluteAssetUrl($url))
                    ->filter(fn ($url) => is_string($url) && ! $isLogoLike($url))
                    ->filter(fn ($url) => is_string($url) && trim($url) !== '')
                    ->unique()
                    ->values()
                    ->all();

                $images = collect(array_merge($images, $dataPhotosImages, $scriptImages, $containerHtmlImages))
                    ->filter(fn ($url) => is_string($url) && ! $isLogoLike($url))
                    ->filter(fn ($url) => is_string($url) && trim($url) !== '')
                    ->map(fn (string $url) => trim($url))
                    ->unique()
                    ->values()
                    ->all();

                if (count($images) > 0) {
                    $this->info('Found real image: '.($images[0] ?? 'N/A'));
                }

                if (count($images) === 0) {
                    $images[] = $placeholderImage;
                }

                $images = array_slice($images, 0, 2);

                // All of the above is to get the images from the ad
                $relativeUrl = $node->filter('a.ad-preview__title')->count() > 0
                    ? $node->filter('a.ad-preview__title')->first()->attr('href')
                    : null;
                $listingUrl = is_string($relativeUrl) && $relativeUrl !== ''
                    ? (Str::startsWith($relativeUrl, 'http') ? $relativeUrl : 'https://www.pisos.com'.$relativeUrl)
                    : 'https://www.pisos.com/venta/pisos-madrid/';

                $canonicalListingUrl = $canonicalizeUrl($listingUrl);
                $externalId = md5($canonicalListingUrl);

                $displayTitle = $title ?: 'Untitled';
                $displayPrice = $price ?: 'N/A';
                $progressPrefix = '[Page '.$page.'/'.$totalPages.'] Ad '.($i + 1).'/'.$adsCount.': '.$displayTitle.' - '.$displayPrice;
                $this->line($progressPrefix);

                if (Listing::query()->where('external_id', $externalId)->exists()) { // Deduplication (We use URL because it's unique and more stable than the external ID)
                    $this->line('   Skipped (already imported): '.$canonicalListingUrl);

                    return;
                }

                try {
                    $structuredInput = json_encode([ // Structured input for AI
                        'title' => $title,
                        'price' => $price,
                        'subtitle' => $neighborhood,
                        'features' => $features,
                        'description' => $description,
                    ], JSON_UNESCAPED_UNICODE);

                    sleep(1); // Wait for 1 second to avoid rate limiting

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

                    // This above is the response given the prompt, we normalize with AI because it's more flexible than regex and can handle free form text well

                    $content = $aiResponse->choices[0]->message->content ?? '';
                    $jsonData = json_decode($content, true);

                    if (! is_array($jsonData)) {
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

                    $this->info('   Saved: '.($listingData['title'] ?? 'Untitled').' - '.($listingData['price'] ?? 'N/A').'€!');
                } catch (\Exception $e) {
                    $this->error('   Error with the AI: '.$e->getMessage());
                }
            });
        }

        $this->info('Process completed! Review database.');
    }
}
