<?php

namespace App\Services;

use App\Models\Listing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class OpenAIService
{
    public function analyzeSmartSearch(string $prompt, array $allCharacteristics): array
    {
        $neighborhoods = Listing::query()
            ->whereNotNull('neighborhood')
            ->distinct()
            ->pluck('neighborhood')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->values()
            ->toArray();

        $stockByNeighborhood = Listing::query()
            ->select('neighborhood', DB::raw('count(*) as total'))
            ->whereNotNull('neighborhood')
            ->groupBy('neighborhood')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'neighborhood' => $row->neighborhood,
                'total' => (int) $row->total,
            ])
            ->values()
            ->toArray();

        $availableTypes = Listing::query()
            ->whereNotNull('type')
            ->distinct()
            ->pluck('type')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->values()
            ->toArray();

        $characteristics = collect($allCharacteristics)
            ->map(fn ($value) => is_string($value) ? Str::lower(trim($value)) : null)
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $prices = Listing::query()
            ->whereNotNull('price')
            ->orderBy('price')
            ->pluck('price')
            ->map(fn ($value) => (int) $value)
            ->values();

        $percentile = static function ($values, float $percent): ?int {
            if ($values->isEmpty()) {
                return null;
            }

            $index = (int) floor(($values->count() - 1) * $percent);

            return (int) $values->get($index);
        };

        $cheapThreshold = $percentile($prices, 0.20);
        $luxuryThreshold = $percentile($prices, 0.90);

        $aiResponse = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a Geographic Expert of Madrid and a Senior Real Estate Consultant. Your job is to map vague human desires to technical database filters.

You MUST only use values from these real lists:
REAL_NEIGHBORHOODS: '.json_encode($neighborhoods, JSON_UNESCAPED_UNICODE).'
REAL_TYPES: '.json_encode($availableTypes, JSON_UNESCAPED_UNICODE).'
CHARACTERISTICS: '.json_encode($characteristics, JSON_UNESCAPED_UNICODE).'

Surreal Estate Logic:
- Adjective mapping:
  - "spacious" => minSize >= 120
  - "cozy" => maxSize <= 60
  - "cheap" => maxPrice near or below bottom 20% market threshold ('.($cheapThreshold ?? 'null').')
  - "luxury" => minPrice near or above top 10% market threshold ('.($luxuryThreshold ?? 'null').') and/or premium characteristics (pool, security, elevator, terrace)
- Lifestyle mapping:
  - If user says family/kids/roommates, infer higher minRooms and avoid Studio unless explicitly requested.
- Geographic intel:
  - When a user asks for a location or being "near" a landmark (Retiro, Bernabéu, Castellana), identify ALL neighborhoods from REAL_NEIGHBORHOODS that are within walking distance or directly adjacent.
  - Example: "Near Retiro" -> [Retiro, Salamanca, Ibiza, Cortes, Niño Jesús] (only keep neighborhoods that exist in REAL_NEIGHBORHOODS).
  - Example: "Upscale/Pijo" -> [Salamanca, El Viso, Almagro, Nueva España] (filtered by REAL_NEIGHBORHOODS).
  - Example: "University/Estudiantil" -> [Moncloa, Argüelles, Ciudad Universitaria, Chamberí] (filtered by REAL_NEIGHBORHOODS).
  - If user says "center", map to the most relevant neighborhoods available in REAL_NEIGHBORHOODS.
  - ONLY suggest neighborhoods with at least 1 property in stock.
  - Current stock by neighborhood: '.json_encode($stockByNeighborhood, JSON_UNESCAPED_UNICODE).'
  - If request is broad, select MORE neighborhoods to increase options.
  - If you select a neighborhood with only 1 property, add 2 adjacent neighborhoods from REAL_NEIGHBORHOODS.
  - ALWAYS return neighborhoods as an array, even if it contains only one value.
  - If the request contains a very specific place (street/avenue/landmark), also provide a short searchKeyword for text search backup.
  - Do NOT use searchKeyword for general vibes like "university", "luxury", "quiet", "center". For those, use neighborhoods/price/characteristics only.

Return ONLY a valid JSON object with this exact structure:
{
  "filters": {
    "minPrice": number|null,
    "maxPrice": number|null,
    "neighborhoods": string[]|null,
    "type": string|null,
    "minRooms": number|null,
    "maxSize": number|null,
    "minSize": number|null,
    "searchKeyword": string|null,
    "characteristics": string[]|null
  },
  "logic_summary": string (MUST be written in English, regardless of the language used by the user)
}',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);

        $json = json_decode($aiResponse->choices[0]->message->content ?? '', true);
        $filters = is_array($json['filters'] ?? null) ? $json['filters'] : [];

        return [
            'filters' => [
                'minPrice' => is_numeric($filters['minPrice'] ?? null) ? (int) $filters['minPrice'] : null,
                'maxPrice' => is_numeric($filters['maxPrice'] ?? null) ? (int) $filters['maxPrice'] : null,
                'neighborhoods' => collect(is_array($filters['neighborhoods'] ?? null)
                    ? $filters['neighborhoods']
                    : (is_string($filters['neighborhoods'] ?? null) ? [$filters['neighborhoods']] : []))
                    ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                    ->map(fn (string $value) => trim($value))
                    ->filter(fn (string $value) => in_array($value, $neighborhoods, true))
                    ->unique()
                    ->values()
                    ->all(),
                'type' => is_string($filters['type'] ?? null) ? trim($filters['type']) : null,
                'minRooms' => is_numeric($filters['minRooms'] ?? null) ? (int) $filters['minRooms'] : null,
                'maxSize' => is_numeric($filters['maxSize'] ?? null) ? (int) $filters['maxSize'] : null,
                'minSize' => is_numeric($filters['minSize'] ?? null) ? (int) $filters['minSize'] : null,
                'searchKeyword' => is_string($filters['searchKeyword'] ?? null) ? trim($filters['searchKeyword']) : null,
                'characteristics' => collect($filters['characteristics'] ?? [])
                    ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                    ->map(fn (string $value) => Str::lower(trim($value)))
                    ->filter(fn (string $value) => in_array($value, $characteristics, true))
                    ->unique()
                    ->values()
                    ->all(),
            ],
            'logic_summary' => is_string($json['logic_summary'] ?? null)
                ? trim($json['logic_summary'])
                : 'I analyzed your request and prepared suggested filters.',
        ];
    }

    public function recommendLifestyle(array $input, array $allCharacteristics): array
    {
        $realNeighborhoods = $this->getRealNeighborhoods();
        $realNeighborhoodsList = collect($realNeighborhoods)->implode(', ');
        $realCharacteristics = $this->normalizeCharacteristicList($allCharacteristics);

        $payload = json_encode([
            'work' => [
                'location' => $input['workLocation'] ?? '',
                'frequency' => $input['workFrequency'] ?? '',
            ],
            'family' => [
                'household' => $input['household'] ?? 'Alone',
                'hasPets' => (bool) ($input['hasPets'] ?? false),
            ],
            'financials' => [
                'monthlyNetIncome' => is_numeric($input['income'] ?? null) ? (float) $input['income'] : null,
                'depositSavings' => is_numeric($input['savings'] ?? null) ? (float) $input['savings'] : null,
            ],
            'hobbies' => $input['hobbies'] ?? '',
            'social' => $input['social'] ?? '',
            'priority' => $input['priority'] ?? '',
            'notes' => $input['notes'] ?? '',
        ], JSON_UNESCAPED_UNICODE);

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a Senior Real Estate Investment Consultant in Madrid.
Analyze the user data:
- Affordability: STRICTLY enforce the 30% entry rule (20% downpayment + 10% taxes/fees) and max 33% debt ratio from monthly net income.
- If savings are too low for the requested profile or target areas, you MUST say this clearly in the analysis and suggest more affordable up-and-coming neighborhoods from REAL_NEIGHBORHOODS.
- Commute: Optimize location between Work, Hobbies, and Family landmarks.
- Matching: Map the intent to the available neighborhoods in my DB.
- Neighborhood output constraint: You MUST ONLY return neighborhood names from this exact list and keep original spelling: '.$realNeighborhoodsList.'. Do not invent neighborhoods. Do not include nearby areas unless they appear in the exact list.

REAL_NEIGHBORHOODS: '.json_encode($realNeighborhoods, JSON_UNESCAPED_UNICODE).'
REAL_CHARACTERISTICS: '.json_encode($realCharacteristics, JSON_UNESCAPED_UNICODE).'

Return ONLY valid JSON:
{
  "filters": {
    "neighborhoods": string[]|null,
    "maxPrice": number|null,
    "minRooms": number|null,
    "minSize": number|null,
    "type": string|null,
    "characteristics": string[]|null,
    "searchKeyword": string|null
  },
  "analysis": "A professional 2-paragraph recommendation explaining the chosen areas based on commute and budget."
}',
                ],
                [
                    'role' => 'user',
                    'content' => $payload,
                ],
            ],
        ]);

        $json = json_decode($response->choices[0]->message->content ?? '', true);
        $filters = is_array($json['filters'] ?? null) ? $json['filters'] : [];

        return [
            'filters' => $this->normalizeLifestyleFilters($filters, $realNeighborhoods, $realCharacteristics),
            'analysis' => is_string($json['analysis'] ?? null)
                ? trim($json['analysis'])
                : 'I prepared a lifestyle-based recommendation for your situation.',
        ];
    }

    public function requestLifestyleFallbackFilters(array $currentFilters, array $allCharacteristics): array
    {
        $realNeighborhoods = $this->getRealNeighborhoods();
        $realNeighborhoodsList = collect($realNeighborhoods)->implode(', ');
        $realCharacteristics = $this->normalizeCharacteristicList($allCharacteristics);

        $stockByNeighborhood = Listing::query()
            ->select('neighborhood', DB::raw('count(*) as total'))
            ->whereNotNull('neighborhood')
            ->groupBy('neighborhood')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'neighborhood' => $row->neighborhood,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a Madrid real estate consultant optimizing fallback filters.
Use fallback only when budget is the main constraint.
Do not add random neighborhoods.
Only broaden to adjacent neighborhoods that are in this exact list: '.$realNeighborhoodsList.'
You MUST ONLY return neighborhoods from that list with exact spelling.
If no valid in-list adjacent options exist, return the same neighborhoods unchanged.
Return ONLY JSON with:
{
  "filters": {
    "neighborhoods": string[]|null,
    "maxPrice": number|null,
    "minRooms": number|null,
    "minSize": number|null,
    "type": string|null,
    "characteristics": string[]|null,
    "searchKeyword": string|null
  }
}

REAL_NEIGHBORHOODS_WITH_STOCK: '.json_encode($stockByNeighborhood, JSON_UNESCAPED_UNICODE),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'currentFilters' => $currentFilters,
                        'targetMinimumResults' => 3,
                        'fallbackRule' => 'Only broaden adjacent in-list neighborhoods when budget is the constraint.',
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ],
        ]);

        $json = json_decode($response->choices[0]->message->content ?? '', true);
        if (! is_array($json) || ! is_array($json['filters'] ?? null)) {
            return $this->normalizeLifestyleFilters($currentFilters, $realNeighborhoods, $realCharacteristics);
        }

        return $this->normalizeLifestyleFilters($json['filters'], $realNeighborhoods, $realCharacteristics);
    }

    private function getRealNeighborhoods(): array
    {
        return Listing::query()
            ->whereNotNull('neighborhood')
            ->distinct()
            ->pluck('neighborhood')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->values()
            ->all();
    }

    private function normalizeCharacteristicList(array $allCharacteristics): array
    {
        return collect($allCharacteristics)
            ->map(fn ($value) => is_string($value) ? Str::lower(trim($value)) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeLifestyleFilters(array $filters, array $realNeighborhoods, array $realCharacteristics): array
    {
        return [
            'neighborhoods' => collect($filters['neighborhoods'] ?? [])
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->map(fn (string $value) => trim($value))
                ->filter(fn (string $value) => in_array($value, $realNeighborhoods, true))
                ->unique()
                ->values()
                ->all(),
            'maxPrice' => is_numeric($filters['maxPrice'] ?? null) ? (int) $filters['maxPrice'] : null,
            'minRooms' => is_numeric($filters['minRooms'] ?? null) ? (int) $filters['minRooms'] : null,
            'minSize' => is_numeric($filters['minSize'] ?? null) ? (int) $filters['minSize'] : null,
            'type' => is_string($filters['type'] ?? null) ? trim($filters['type']) : null,
            'searchKeyword' => is_string($filters['searchKeyword'] ?? null) ? trim($filters['searchKeyword']) : null,
            'characteristics' => collect($filters['characteristics'] ?? [])
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->map(fn (string $value) => Str::lower(trim($value)))
                ->filter(fn (string $value) => in_array($value, $realCharacteristics, true))
                ->unique()
                ->values()
                ->all(),
        ];
    }
}
