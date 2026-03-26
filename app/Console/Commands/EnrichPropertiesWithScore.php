<?php

namespace App\Console\Commands;

use App\Models\Listing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OpenAI\Laravel\Facades\OpenAI;

#[Signature('app:enrich-properties-with-score')]
#[Description('Scores existing listings with a Surreal Score using OpenAI')]
class EnrichPropertiesWithScore extends Command
{
    public function handle(): int
    {
        $total = Listing::query()->whereNull('surreal_score')->count();

        if ($total === 0) { // If no listings need scoring, return success
            $this->info('No listings need scoring.');

            return self::SUCCESS;
        }

        $this->info('Scoring '.$total.' listings...');

        $processed = 0;

        Listing::query()
            ->whereNull('surreal_score')
            ->orderBy('id')
            ->chunkById(50, function ($listings) use (&$processed, $total): void { // Chunk the listings into 50 listings at a time for easy management
                foreach ($listings as $listing) {
                    $processed++;

                    try {
                        $payload = json_encode([ // Structured input for AI
                            'price' => $listing->price,
                            'size' => $listing->size,
                            'neighborhood' => $listing->neighborhood,
                            'rooms' => $listing->rooms,
                            'type' => $listing->type,
                            'characteristics' => $listing->characteristics,
                        ], JSON_UNESCAPED_UNICODE);

                        $response = OpenAI::chat()->create([
                            'model' => 'gpt-4o-mini',
                            'response_format' => ['type' => 'json_object'],
                            'messages' => [
                                [ // Made the prompt give variance in the scores and be bold because it tended to be bland and boring
                                    'role' => 'system',
                                    'content' => 'You are a cynical, high-end Madrid Real Estate Critic. Your scores must have high variance. Do NOT give everyone a 6 or 7. 

The Scale:
9.0 - 10 (Surreal): Only for absolute unicorns. A mansion in Salamanca at a 3-bedroom price. High-quality photos + amazing price/m2 ratio.
7.0 - 8.5 (Great Deal): Solid opportunities. Better than anything else in that specific street.
5.0 - 6.5 (Average): The market standard. Fair price, fair house. Boring.
1.0 - 4.5 (Rip-off): Overpriced studios, interior "caves" with no light, or overpriced suburban flats. Be brutal.

The Analysis:
Compare the price_per_m2 with the neighborhood average (use your internal data for Madrid 2024-2026).
Penalize small sizes if the price is high.
Reward luxury features (pool, attic, terrace) ONLY if the price does not skyrocket.

The Output:
Return ONLY a valid JSON object with:
- score (float between 1.0 and 10.0)
- reason (one short, punchy, bold headline-style sentence in English, e.g. "A steal in the heart of Almagro" or "Grossly overpriced for a basement flat").',
                                ],
                                [
                                    'role' => 'user',
                                    'content' => $payload,
                                ],
                            ],
                        ]);

                        $json = json_decode($response->choices[0]->message->content ?? '', true);
                        $rawScore = $json['score'] ?? null;
                        $reason = is_string($json['reason'] ?? null) ? trim($json['reason']) : null;

                        if (! is_numeric($rawScore) || $reason === null || $reason === '') { // If the score is not numeric or the reason is null or empty, throw an error
                            throw new \RuntimeException('Invalid AI score payload.');
                        }

                        $score = round(max(1.0, min(10.0, (float) $rawScore)), 2); // Rounds the score to 2 decimal places and ensures it's between 1.0 and 10.0

                        $listing->surreal_score = $score;
                        $listing->surreal_reason = $reason;
                        $listing->save();

                        $this->line('[Scoring] Property '.$processed.'/'.$total.': Score '.$score.'.');
                    } catch (\Throwable $exception) {
                        $this->error('[Scoring] Property '.$processed.'/'.$total.': Error - '.$exception->getMessage());
                    }
                }
            });

        $this->info('Scoring process completed.');

        return self::SUCCESS;
    }
}
