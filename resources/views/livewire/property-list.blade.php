<?php

use App\Services\ListingQueryService;
use App\Services\OpenAIService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{ // filters
    use WithPagination;
    
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $minPrice = '';

    #[Url(except: '')]
    public string $maxPrice = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $minSize = '';

    #[Url(except: '')]
    public string $maxSize = '';

    #[Url(except: '')]
    public string $minRooms = '';

    #[Url(except: '')]
    public string $minBathrooms = '';

    #[Url(except: 'price')]
    public string $sortBy = 'price';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';


    // Smart search and lifestyle filters
    public array $selectedNeighborhoods = []; 
    public array $selectedCharacteristics = [];
    public string $aiSearchPrompt = '';
    public string $aiExplanation = '';
    public array $aiDetectedFilters = [];
    public bool $aiPreviewReady = false;
    public int $aiPreviewCount = 0;
    public string $aiWarning = '';
    public bool $isAnalyzing = false;

    public int $lifestyleStep = 1;
    public string $lifestyleWorkLocation = '';
    public string $lifestyleWorkFrequency = '';
    public string $lifestyleHousehold = 'Alone';
    public bool $lifestyleHasPets = false;
    public string $lifestyleIncome = '';
    public string $lifestyleSavings = '';
    public string $lifestyleHobbies = '';
    public string $lifestyleSocial = '';
    public string $lifestylePriority = '';
    public string $lifestyleNotes = '';
    public bool $isLifestyleAnalyzing = false;
    public bool $lifestyleReady = false;
    public string $lifestyleAnalysis = '';
    public array $lifestyleDetectedFilters = [];
    public string $lifestyleWarning = '';

    public function rendering($view): void
    {
        $view->layout('layouts.app');
    }

    private function listingQueryService(): ListingQueryService
    {
        return app(ListingQueryService::class);
    }

    private function openAIService(): OpenAIService
    {
        return app(OpenAIService::class);
    }

    public function getAllCharacteristicsProperty(): array
    {
        return $this->listingQueryService()->getAllCharacteristics();
    }

    public function getPropertiesProperty(): LengthAwarePaginator
    {
        return $this->listingQueryService()->getProperties([
            'search' => $this->search,
            'minPrice' => $this->minPrice,
            'maxPrice' => $this->maxPrice,
            'type' => $this->type,
            'state' => $this->state,
            'selectedNeighborhoods' => $this->selectedNeighborhoods,
            'minSize' => $this->minSize,
            'maxSize' => $this->maxSize,
            'minRooms' => $this->minRooms,
            'minBathrooms' => $this->minBathrooms,
            'selectedCharacteristics' => $this->selectedCharacteristics,
            'sortBy' => $this->sortBy,
            'sortDirection' => $this->sortDirection,
        ], 12);
    }

    public function updated($name): void
    {
        if ($name !== 'page') {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->minPrice = '';
        $this->maxPrice = '';
        $this->type = '';
        $this->state = '';
        $this->minSize = '';
        $this->maxSize = '';
        $this->minRooms = '';
        $this->minBathrooms = '';
        $this->sortBy = 'price';
        $this->sortDirection = 'desc';
        $this->selectedNeighborhoods = [];
        $this->selectedCharacteristics = [];
        $this->resetPage();
    }

    public function applySmartSearch(): void // Applies the smart search by calling the AI service and applying the filters to the query
    {
        $searchTerms = str_word_count(trim($this->aiSearchPrompt));

        if ($searchTerms <= 3) {
            return;
        }

        $this->isAnalyzing = true;
        $this->aiPreviewReady = false;
        $this->aiWarning = '';
        $this->aiPreviewCount = 0;

        try {
            $recommendation = $this->openAIService()->analyzeSmartSearch($this->aiSearchPrompt, $this->allCharacteristics);

            $this->aiDetectedFilters = is_array($recommendation['filters'] ?? null) ? $recommendation['filters'] : [];
            $this->aiExplanation = is_string($recommendation['logic_summary'] ?? null)
                ? trim($recommendation['logic_summary'])
                : 'I analyzed your request and prepared suggested filters.';

            $this->aiPreviewCount = $this->listingQueryService()->getSmartSearchPreviewCount($this->aiDetectedFilters);

            if ($this->aiPreviewCount === 0) {
                $this->aiWarning = 'No properties match these filters yet. Please broaden your request (more neighborhoods or fewer constraints).';
            }

            $this->aiPreviewReady = true;
        } catch (\Throwable $e) {
            Log::warning('Smart Search consultant failed', ['exception' => $e]);
            $this->aiExplanation = 'The Consultant is busy right now. Please try again in a moment.';
            $this->aiDetectedFilters = [];
            $this->aiPreviewCount = 0;
            $this->aiPreviewReady = true;
        } finally {
            $this->isAnalyzing = false;
        }
    }

    public function confirmAndApplySmartSearch(): void // Confirms the smart search by applying the filters to the query
    {
        if (count($this->aiDetectedFilters) === 0) {
            return;
        }

        $this->minPrice = isset($this->aiDetectedFilters['minPrice']) && is_numeric($this->aiDetectedFilters['minPrice'])
            ? (string) (int) $this->aiDetectedFilters['minPrice']
            : '';
        $this->maxPrice = isset($this->aiDetectedFilters['maxPrice']) && is_numeric($this->aiDetectedFilters['maxPrice'])
            ? (string) (int) $this->aiDetectedFilters['maxPrice']
            : '';
        $this->type = is_string($this->aiDetectedFilters['type'] ?? null) ? trim($this->aiDetectedFilters['type']) : '';
        $this->minRooms = isset($this->aiDetectedFilters['minRooms']) && is_numeric($this->aiDetectedFilters['minRooms'])
            ? (string) (int) $this->aiDetectedFilters['minRooms']
            : '';
        $this->minSize = isset($this->aiDetectedFilters['minSize']) && is_numeric($this->aiDetectedFilters['minSize'])
            ? (string) (int) $this->aiDetectedFilters['minSize']
            : '';
        $this->maxSize = isset($this->aiDetectedFilters['maxSize']) && is_numeric($this->aiDetectedFilters['maxSize'])
            ? (string) (int) $this->aiDetectedFilters['maxSize']
            : '';
        $this->selectedNeighborhoods = is_array($this->aiDetectedFilters['neighborhoods'] ?? null)
            ? $this->aiDetectedFilters['neighborhoods']
            : [];
        $this->search = is_string($this->aiDetectedFilters['searchKeyword'] ?? null)
            ? trim($this->aiDetectedFilters['searchKeyword'])
            : '';
        $this->selectedCharacteristics = is_array($this->aiDetectedFilters['characteristics'] ?? null)
            ? $this->aiDetectedFilters['characteristics']
            : [];

        if ($this->listingQueryService()->getSmartSearchPreviewCount($this->aiDetectedFilters) === 0) {
            $this->aiWarning = 'These filters currently return no properties. Please broaden your Smart Search.';
            return;
        }

        $this->resetPage();
    }

    public function resetLifestyleAdvisor(): void
    {
        $this->lifestyleStep = 1;
        $this->lifestyleReady = false;
        $this->isLifestyleAnalyzing = false;
        $this->lifestyleAnalysis = '';
        $this->lifestyleDetectedFilters = [];
        $this->lifestyleWarning = '';
    }

    public function nextLifestyleStep(): void
    {
        if ($this->lifestyleStep < 6) {
            $this->lifestyleStep++;
        }
    }

    public function previousLifestyleStep(): void
    {
        if ($this->lifestyleStep > 1) {
            $this->lifestyleStep--;
        }
    }

    public function recommendLifestyle(): void
    {
        $this->isLifestyleAnalyzing = true;
        $this->lifestyleReady = false;
        $this->lifestyleWarning = '';

        try {
            $recommendation = $this->openAIService()->recommendLifestyle([
                'workLocation' => $this->lifestyleWorkLocation,
                'workFrequency' => $this->lifestyleWorkFrequency,
                'household' => $this->lifestyleHousehold,
                'hasPets' => $this->lifestyleHasPets,
                'income' => $this->lifestyleIncome,
                'savings' => $this->lifestyleSavings,
                'hobbies' => $this->lifestyleHobbies,
                'social' => $this->lifestyleSocial,
                'priority' => $this->lifestylePriority,
                'notes' => $this->lifestyleNotes,
            ], $this->allCharacteristics);

            $this->lifestyleDetectedFilters = is_array($recommendation['filters'] ?? null) ? $recommendation['filters'] : [];
            $this->lifestyleAnalysis = is_string($recommendation['analysis'] ?? null)
                ? trim($recommendation['analysis'])
                : 'I prepared a lifestyle-based recommendation for your situation.';
            $this->lifestyleReady = true;
        } catch (\Throwable $e) {
            Log::warning('Lifestyle consultant failed', ['exception' => $e]);
            $this->lifestyleAnalysis = 'The Lifestyle Consultant is thinking. Please try again in a moment.';
            $this->lifestyleDetectedFilters = [];
            $this->lifestyleReady = true;
        } finally {
            $this->isLifestyleAnalyzing = false;
        }
    }

    public function applyLifestyleRecommendation(): void
    {
        if (! $this->lifestyleReady || count($this->lifestyleDetectedFilters) === 0) {
            return;
        }

        $this->lifestyleWarning = '';
        $candidateFilters = $this->lifestyleDetectedFilters;
        $previewCount = $this->listingQueryService()->getLifestylePreviewCount($candidateFilters);
        $hasBudgetConstraint = is_numeric($candidateFilters['maxPrice'] ?? null);

        if ($previewCount === 0 && $hasBudgetConstraint) {
            try {
                $fallbackFilters = $this->openAIService()->requestLifestyleFallbackFilters($candidateFilters, $this->allCharacteristics);

                $fallbackCount = $this->listingQueryService()->getLifestylePreviewCount($fallbackFilters);
                if ($fallbackCount > 0) {
                    $candidateFilters = $fallbackFilters;
                    $previewCount = $fallbackCount;
                    $this->lifestyleAnalysis .= "\n\nI broadened the recommendation to adjacent in-list neighborhoods with current stock while respecting your budget constraints.";
                }
            } catch (\Throwable $e) {
                Log::warning('Lifestyle fallback filters failed', ['exception' => $e]);
                $this->lifestyleWarning = 'We could not widen the search automatically. You can try again in a moment or adjust your budget.';
            }
        }

        if ($previewCount === 0) {
            if ($this->lifestyleWarning === '') {
                $this->lifestyleWarning = 'We found great areas for you, but we currently have no listings there. Try broadening your budget or features.';
            }

            return;
        }

        $this->lifestyleDetectedFilters = $candidateFilters;

        $this->selectedNeighborhoods = is_array($candidateFilters['neighborhoods'] ?? null)
            ? $candidateFilters['neighborhoods']
            : [];
        $this->maxPrice = isset($candidateFilters['maxPrice']) && is_numeric($candidateFilters['maxPrice'])
            ? (string) (int) $candidateFilters['maxPrice']
            : '';
        $this->minRooms = isset($candidateFilters['minRooms']) && is_numeric($candidateFilters['minRooms'])
            ? (string) (int) $candidateFilters['minRooms']
            : '';
        $this->minSize = isset($candidateFilters['minSize']) && is_numeric($candidateFilters['minSize'])
            ? (string) (int) $candidateFilters['minSize']
            : '';
        $this->type = is_string($candidateFilters['type'] ?? null) ? trim($candidateFilters['type']) : '';
        $this->selectedCharacteristics = is_array($candidateFilters['characteristics'] ?? null)
            ? $candidateFilters['characteristics']
            : [];
        $this->search = is_string($candidateFilters['searchKeyword'] ?? null)
            ? trim($candidateFilters['searchKeyword'])
            : '';

        Log::info('AI Recommended vs Applied to Livewire', [
            'ai_recommended' => $this->lifestyleDetectedFilters,
            'applied_to_livewire' => [
                'selectedNeighborhoods' => $this->selectedNeighborhoods,
                'maxPrice' => $this->maxPrice,
                'minRooms' => $this->minRooms,
                'minSize' => $this->minSize,
                'type' => $this->type,
                'selectedCharacteristics' => $this->selectedCharacteristics,
                'search' => $this->search,
            ],
        ]);

        $this->resetPage();
        $this->dispatch('close-lifestyle-advisor');
    }

};
?>
<!-- Main section of the frontend where the user can see the listings and the filters -->   
<section class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="mb-8">
        <flux:heading size="xl">Surreal Estate</flux:heading>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Discover curated listings in Madrid with smart filters.</p>
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <flux:modal.trigger name="ai-search-modal">
                <flux:button variant="primary" icon="sparkles">
                    Smart Search Assistant
                </flux:button>
            </flux:modal.trigger>

            <flux:modal.trigger name="lifestyle-advisor">
                <flux:button variant="outline" icon="chat-bubble-left-right" wire:click="resetLifestyleAdvisor">
                    Lifestyle Consultant
                </flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <flux:modal name="ai-search-modal" variant="center" class="md:max-w-xl">
        <div class="space-y-4 p-1">
            <flux:heading size="lg">Smart Search Assistant</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Describe your ideal home and I will convert it into precise filters.
            </p>

            <flux:textarea
                wire:model="aiSearchPrompt"
                rows="6"
                placeholder="Example: We are a family with two kids looking for a bright 3-bedroom flat with elevator and terrace near the center, budget up to 700000."
            />

            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="applySmartSearch" :disabled="$isAnalyzing">
                    Find my dream home
                </flux:button>
            </div>

            <div wire:loading.flex wire:target="applySmartSearch" class="items-center gap-2 rounded-md bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                <flux:icon name="sparkles" class="size-4 animate-pulse" />
                <span>Analyzing your search request...</span>
            </div>

            @if ($aiPreviewReady)
                <flux:card variant="subtle" class="space-y-3 p-3">
                    <flux:heading size="sm">AI recommendation</flux:heading>
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $aiExplanation }}
                    </p>

                    <flux:badge size="sm" color="blue">
                        Estimated matches: {{ $aiPreviewCount }}
                    </flux:badge>

                    @if ($aiWarning !== '')
                        <flux:badge size="sm" color="amber">
                            {{ $aiWarning }}
                        </flux:badge>
                    @endif

                    <flux:heading size="xs">Technical Preview</flux:heading>
                    <div class="flex flex-wrap gap-2">
                        @foreach (($aiDetectedFilters['neighborhoods'] ?? []) as $neighborhood)
                            <flux:badge size="sm" color="zinc">Neighborhood: {{ $neighborhood }}</flux:badge>
                        @endforeach
                        @if (!empty($aiDetectedFilters['type']))
                            <flux:badge size="sm" color="zinc">Type: {{ $aiDetectedFilters['type'] }}</flux:badge>
                        @endif
                        @if (!empty($aiDetectedFilters['minPrice']))
                            <flux:badge size="sm" color="zinc">Min €{{ number_format((int) $aiDetectedFilters['minPrice'], 0, ',', '.') }}</flux:badge>
                        @endif
                        @if (!empty($aiDetectedFilters['maxPrice']))
                            <flux:badge size="sm" color="zinc">Max €{{ number_format((int) $aiDetectedFilters['maxPrice'], 0, ',', '.') }}</flux:badge>
                        @endif
                        @if (!empty($aiDetectedFilters['minRooms']))
                            <flux:badge size="sm" color="zinc">Rooms: {{ (int) $aiDetectedFilters['minRooms'] }}+</flux:badge>
                        @endif
                        @if (!empty($aiDetectedFilters['minSize']))
                            <flux:badge size="sm" color="zinc">Min {{ (int) $aiDetectedFilters['minSize'] }} m²</flux:badge>
                        @endif
                        @if (!empty($aiDetectedFilters['maxSize']))
                            <flux:badge size="sm" color="zinc">Max {{ (int) $aiDetectedFilters['maxSize'] }} m²</flux:badge>
                        @endif
                        @if (!empty($aiDetectedFilters['searchKeyword']))
                            <flux:badge size="sm" color="zinc">Text backup: {{ $aiDetectedFilters['searchKeyword'] }}</flux:badge>
                        @endif
                        @foreach (($aiDetectedFilters['characteristics'] ?? []) as $characteristic)
                            <flux:badge size="sm" color="zinc">{{ ucfirst($characteristic) }}</flux:badge>
                        @endforeach
                    </div>

                    <div class="flex justify-end">
                        @if ($aiPreviewCount > 0)
                            <flux:button variant="primary" wire:click="confirmAndApplySmartSearch">
                                Confirm & Apply
                            </flux:button>
                        @else
                            <flux:button variant="ghost" wire:click="applySmartSearch">
                                Broaden Search
                            </flux:button>
                        @endif
                    </div>
                </flux:card>
            @endif
        </div>
    </flux:modal>

    <flux:modal name="lifestyle-advisor" class="md:max-w-2xl" x-on:close-lifestyle-advisor.window="$flux.modal('lifestyle-advisor').close()">
        @php
            $lifestyleProgress = min(100, max(0, (int) round(($lifestyleStep / 6) * 100)));
        @endphp

        <div class="space-y-4 p-1">
            <flux:heading size="lg">Lifestyle Consultant</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Answer a few focused questions and receive a personalized recommendation based on commute, budget, and neighborhood fit.
            </p>

            <div class="space-y-1">
                <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                    <div class="h-full bg-blue-600 transition-all" style="width: {{ $lifestyleProgress }}%;"></div>
                </div>
                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                    Step {{ $lifestyleStep }} of 6
                </flux:text>
            </div>

            @if (!$lifestyleReady)
                @if ($lifestyleStep === 1)
                    <div class="space-y-3">
                        <flux:heading size="sm">Work</flux:heading>
                        <flux:input
                            wire:model="lifestyleWorkLocation"
                            label="Where do you work/study?"
                            placeholder="Office, campus, or key landmark"
                        />
                        <flux:select wire:model="lifestyleWorkFrequency" label="How often do you go there?">
                            <option value="">Select frequency</option>
                            <option value="daily">Daily</option>
                            <option value="3-4 days/week">3-4 days/week</option>
                            <option value="1-2 days/week">1-2 days/week</option>
                            <option value="occasionally">Occasionally</option>
                            <option value="remote">Fully remote</option>
                        </flux:select>
                    </div>
                @elseif ($lifestyleStep === 2)
                    <div class="space-y-3">
                        <flux:heading size="sm">Family</flux:heading>
                        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">Who are you moving with?</flux:text>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            @foreach (['Alone', 'Partner', 'Family'] as $household)
                                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800">
                                    <input type="radio" wire:model="lifestyleHousehold" value="{{ $household }}" class="h-4 w-4" />
                                    <span>{{ $household }}</span>
                                </label>
                            @endforeach
                        </div>
                        <flux:checkbox wire:model.live="lifestyleHasPets" label="Do you have pets?" />
                    </div>
                @elseif ($lifestyleStep === 3)
                    <div class="space-y-3">
                        <flux:heading size="sm">Financials</flux:heading>
                        <flux:input
                            wire:model="lifestyleIncome"
                            type="number"
                            min="0"
                            label="Monthly net income (€)"
                            placeholder="4500"
                        />
                        <flux:input
                            wire:model="lifestyleSavings"
                            type="number"
                            min="0"
                            label="Total savings for deposit (€)"
                            placeholder="90000"
                        />
                    </div>
                @elseif ($lifestyleStep === 4)
                    <div class="space-y-3">
                        <flux:heading size="sm">Hobbies</flux:heading>
                        <flux:input
                            wire:model="lifestyleHobbies"
                            label="What are your hobbies and where do you practice them?"
                            placeholder="Padel in Chamartin, gym in Chamberi, etc."
                        />
                    </div>
                @elseif ($lifestyleStep === 5)
                    <div class="space-y-3">
                        <flux:heading size="sm">Social</flux:heading>
                        <flux:input
                            wire:model="lifestyleSocial"
                            label="Do you want to live near family/friends? Where are they?"
                            placeholder="Family in Retiro, friends near Sol..."
                        />
                    </div>
                @else
                    <div class="space-y-3">
                        <flux:heading size="sm">Priority & Final Notes</flux:heading>
                        <flux:select wire:model="lifestylePriority" label="What is your non-negotiable?">
                            <option value="">Select one</option>
                            <option value="Price">Price</option>
                            <option value="Location">Location</option>
                            <option value="Property Quality">Property Quality</option>
                        </flux:select>
                        <flux:textarea
                            wire:model="lifestyleNotes"
                            rows="4"
                            label="Anything else the AI should know?"
                            placeholder="Any extra constraints or preferences..."
                        />
                    </div>
                @endif

                <div class="flex items-center justify-between pt-2">
                    <flux:button variant="ghost" wire:click="previousLifestyleStep" :disabled="$lifestyleStep <= 1">
                        Back
                    </flux:button>

                    @if ($lifestyleStep < 6)
                        <flux:button variant="primary" wire:click="nextLifestyleStep">
                            Continue
                        </flux:button>
                    @else
                        <flux:button variant="primary" wire:click="recommendLifestyle" :disabled="$isLifestyleAnalyzing">
                            Generate Recommendation
                        </flux:button>
                    @endif
                </div>

                <div wire:loading.flex wire:target="recommendLifestyle" class="flex-col items-start gap-2 rounded-md bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                    <div class="flex items-center gap-2">
                        <flux:icon name="sparkles" class="size-4 animate-pulse" />
                        <span class="font-medium">Thinking...</span>
                    </div>
                    <ul class="space-y-1 text-xs text-zinc-600 dark:text-zinc-400">
                        <li>Calculating commute times...</li>
                        <li>Checking financial viability (30% entry rule)...</li>
                        <li>Matching neighborhoods with live inventory...</li>
                    </ul>
                </div>
            @else
                <flux:card variant="subtle" class="space-y-3 p-3">
                    <flux:heading size="sm">Recommendation Analysis</flux:heading>
                    <p class="whitespace-pre-line text-sm text-zinc-700 dark:text-zinc-300">
                        {{ $lifestyleAnalysis }}
                    </p>

                    <flux:heading size="xs">Technical Filters</flux:heading>
                    <div class="flex flex-wrap gap-2">
                        @foreach (($lifestyleDetectedFilters['neighborhoods'] ?? []) as $neighborhood)
                            <flux:badge size="sm" color="zinc">Neighborhood: {{ $neighborhood }}</flux:badge>
                        @endforeach
                        @if (!empty($lifestyleDetectedFilters['maxPrice']))
                            <flux:badge size="sm" color="zinc">Max €{{ number_format((int) $lifestyleDetectedFilters['maxPrice'], 0, ',', '.') }}</flux:badge>
                        @endif
                        @if (!empty($lifestyleDetectedFilters['minRooms']))
                            <flux:badge size="sm" color="zinc">Rooms: {{ (int) $lifestyleDetectedFilters['minRooms'] }}+</flux:badge>
                        @endif
                        @if (!empty($lifestyleDetectedFilters['minSize']))
                            <flux:badge size="sm" color="zinc">Min {{ (int) $lifestyleDetectedFilters['minSize'] }} m²</flux:badge>
                        @endif
                        @if (!empty($lifestyleDetectedFilters['type']))
                            <flux:badge size="sm" color="zinc">Type: {{ $lifestyleDetectedFilters['type'] }}</flux:badge>
                        @endif
                    </div>

                    @if ($lifestyleWarning !== '')
                        <flux:badge color="amber" size="sm">
                            {{ $lifestyleWarning }}
                        </flux:badge>
                    @endif

                    <div class="flex justify-end">
                        <flux:button
                            variant="primary"
                            wire:click="applyLifestyleRecommendation"
                        >
                            Show matching properties
                        </flux:button>
                    </div>
                </flux:card>
            @endif
        </div>
    </flux:modal>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <aside class="lg:col-span-3">
            <flux:card class="sticky top-6 p-5">
                <div class="space-y-6">
                    <flux:heading size="lg">Filters</flux:heading>

                    <div class="space-y-3">
                        <flux:heading size="sm">Basic</flux:heading>
                        <flux:input
                            wire:model.live.debounce.300ms="search"
                            label="Search"
                            placeholder="Title, area or description"
                        />

                        <flux:select wire:model.live="type" label="Property type">
                            <option value="">All types</option>
                            <option value="Flat">Flat</option>
                            <option value="House">House</option>
                            <option value="Penthouse">Penthouse</option>
                            <option value="Studio">Studio</option>
                        </flux:select>

                        <flux:select wire:model.live="state" label="Condition">
                            <option value="">All conditions</option>
                            <option value="New">New</option>
                            <option value="Used">Used</option>
                            <option value="To renovate">To renovate</option>
                        </flux:select>
                    </div>

                    <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                        <flux:heading size="sm">Price & Size</flux:heading>
                        <flux:input
                            wire:model.live.debounce.300ms="minPrice"
                            type="number"
                            min="0"
                            label="Min price"
                            placeholder="0"
                        />

                        <flux:input
                            wire:model.live.debounce.300ms="maxPrice"
                            type="number"
                            min="0"
                            label="Max price"
                            placeholder="1000000"
                        />

                        <flux:input
                            wire:model.live.debounce.300ms="minSize"
                            type="number"
                            min="0"
                            label="Min size (m²)"
                            placeholder="50"
                        />

                        <flux:input
                            wire:model.live.debounce.300ms="maxSize"
                            type="number"
                            min="0"
                            label="Max size (m²)"
                            placeholder="200"
                        />
                    </div>

                    <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                        <flux:heading size="sm">Features</flux:heading>
                        <flux:input
                            wire:model.live.debounce.300ms="minRooms"
                            type="number"
                            min="0"
                            label="Min rooms"
                            placeholder="2"
                        />

                        <flux:input
                            wire:model.live.debounce.300ms="minBathrooms"
                            type="number"
                            min="0"
                            label="Min bathrooms"
                            placeholder="1"
                        />
                    </div>

                    <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                        <flux:heading size="sm">Characteristics</flux:heading>

                        <div class="grid max-h-56 grid-cols-2 gap-2 overflow-y-auto pr-1">
                            @forelse ($this->allCharacteristics as $characteristic)
                                <flux:checkbox
                                    wire:model.live="selectedCharacteristics"
                                    value="{{ $characteristic }}"
                                    label="{{ ucfirst($characteristic) }}"
                                />
                            @empty
                                <p class="col-span-2 text-xs text-zinc-500 dark:text-zinc-400">No characteristics available yet.</p>
                            @endforelse
                        </div>
                    </div>

                    <flux:button variant="primary" wire:click="clearFilters" class="w-full">Reset all filters</flux:button>
                </div>
            </flux:card>
        </aside>

        <main class="lg:col-span-9">
            <flux:card class="mb-3 px-4 py-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">
                        {{ number_format($this->properties->total(), 0, ',', '.') }} results found
                    </flux:text>

                    <div class="flex flex-wrap items-center gap-2">
                        <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">
                            Sort by:
                        </flux:text>

                        <flux:select wire:model.live="sortBy" label="" size="sm">
                            <option value="id">Newest</option>
                            <option value="price">Price</option>
                            <option value="size">Size</option>
                            <option value="surreal_score">Surreal Score</option>
                        </flux:select>

                        <flux:select wire:model.live="sortDirection" label="" size="sm">
                            <option value="desc">Descending</option>
                            <option value="asc">Ascending</option>
                        </flux:select>
                    </div>
                </div>
            </flux:card>

            @php
                $hasActiveFilters = $search !== ''
                    || $type !== ''
                    || $state !== ''
                    || count($selectedNeighborhoods) > 0
                    || $minPrice !== ''
                    || $maxPrice !== ''
                    || $minSize !== ''
                    || $maxSize !== ''
                    || $minRooms !== ''
                    || $minBathrooms !== ''
                    || count($selectedCharacteristics) > 0;
            @endphp

            @if ($hasActiveFilters)
                <flux:card class="mb-3 px-3 py-1.5">
                    @php
                        $visibleCharacteristics = collect($selectedCharacteristics)->take(6)->all();
                        $hiddenCharacteristicsCount = max(count($selectedCharacteristics) - count($visibleCharacteristics), 0);
                    @endphp

                    <div class="flex flex-wrap items-center gap-1.5">
                        <flux:badge color="blue" size="sm">Active filters</flux:badge>

                        @if ($search !== '')
                            <flux:badge size="sm" color="zinc">Search: {{ $search }}</flux:badge>
                        @endif
                        @if ($type !== '')
                            <flux:badge size="sm" color="zinc">Type: {{ $type }}</flux:badge>
                        @endif
                        @if ($state !== '')
                            <flux:badge size="sm" color="zinc">State: {{ $state }}</flux:badge>
                        @endif
                        @foreach ($selectedNeighborhoods as $neighborhood)
                            <flux:badge size="sm" color="zinc">Neighborhood: {{ $neighborhood }}</flux:badge>
                        @endforeach
                        @if ($minPrice !== '')
                            <flux:badge size="sm" color="zinc">Min €{{ number_format((int) $minPrice, 0, ',', '.') }}</flux:badge>
                        @endif
                        @if ($maxPrice !== '')
                            <flux:badge size="sm" color="zinc">Max €{{ number_format((int) $maxPrice, 0, ',', '.') }}</flux:badge>
                        @endif
                        @if ($minSize !== '')
                            <flux:badge size="sm" color="zinc">Min {{ (int) $minSize }} m²</flux:badge>
                        @endif
                        @if ($maxSize !== '')
                            <flux:badge size="sm" color="zinc">Max {{ (int) $maxSize }} m²</flux:badge>
                        @endif
                        @if ($minRooms !== '')
                            <flux:badge size="sm" color="zinc">Rooms: {{ (int) $minRooms }}+</flux:badge>
                        @endif
                        @if ($minBathrooms !== '')
                            <flux:badge size="sm" color="zinc">Baths: {{ (int) $minBathrooms }}+</flux:badge>
                        @endif
                        @foreach ($visibleCharacteristics as $characteristic)
                            <flux:badge size="sm" color="zinc">{{ ucfirst($characteristic) }}</flux:badge>
                        @endforeach
                        @if ($hiddenCharacteristicsCount > 0)
                            <flux:badge size="sm" color="zinc">+{{ $hiddenCharacteristicsCount }} more</flux:badge>
                        @endif

                        <flux:button variant="ghost" size="sm" wire:click="clearFilters" class="ml-auto">
                            Reset
                        </flux:button>
                    </div>
                </flux:card>
            @endif

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($this->properties as $property)
                    @php
                        $modalName = 'detail-modal-' . $this->properties->currentPage() . '-' . $property->id;
                        $propertyImages = collect($property->images ?? [])
                            ->filter(fn ($image) => is_string($image) && trim($image) !== '')
                            ->unique()
                            ->values()
                            ->all();
                        $mainImage = $propertyImages[0] ?? 'https://placehold.co/1200x800?text=No+Image';
                        $galleryImages = array_slice($propertyImages, 0, 2);
                        if (count($galleryImages) === 0) {
                            $galleryImages[] = 'https://placehold.co/1200x800?text=No+Image';
                        }
                        while (count($galleryImages) < 2) {
                            $galleryImages[] = $galleryImages[0];
                        }
                        $photoCount = max(count($propertyImages), 1);
                        $surrealScore = is_numeric($property->surreal_score ?? null) ? (float) $property->surreal_score : null;
                        $scoreBadgeClasses = match (true) {
                            $surrealScore !== null && $surrealScore >= 8.0 => 'bg-emerald-500 text-white',
                            $surrealScore !== null && $surrealScore >= 5.0 => 'bg-amber-500 text-zinc-900',
                            $surrealScore !== null => 'bg-rose-500 text-white',
                            default => 'bg-zinc-700 text-white',
                        };
                    @endphp

                    <div wire:key="property-item-{{ $this->properties->currentPage() }}-{{ $property->id }}">
                        <flux:modal.trigger name="{{ $modalName }}">
                            <div class="block w-full">
                                <flux:card class="flex h-full cursor-pointer flex-col gap-4 p-4 transition hover:bg-zinc-50/50 dark:hover:bg-zinc-900/60">
                                    <div class="relative overflow-hidden rounded-xl">
                                        <img
                                            src="{{ $mainImage }}"
                                            alt="{{ $property->title ?? 'Property image' }}"
                                            class="h-48 w-full object-cover"
                                            loading="lazy"
                                        />
                                        <div class="absolute right-2 top-2">
                                            <flux:badge size="sm" color="zinc">
                                                1/{{ $photoCount }}
                                            </flux:badge>
                                        </div>
                                        <div class="absolute left-2 top-2">
                                            <span class="{{ $scoreBadgeClasses }} inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold shadow-sm">
                                                Surreal {{ $surrealScore !== null ? number_format($surrealScore, 1) : 'N/A' }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <flux:heading size="lg">
                                            €{{ number_format((int) ($property->price ?? 0), 0, ',', '.') }}
                                        </flux:heading>

                                        <p class="line-clamp-2 break-words font-semibold text-blue-600 dark:text-blue-400">
                                            {{ $property->neighborhood ?? 'Neighborhood unavailable' }}
                                        </p>

                                        <p class="line-clamp-3 text-sm text-zinc-600 dark:text-zinc-300">
                                            {{ $property->description ?? 'No description available.' }}
                                        </p>
                                    </div>

                                    <div class="grid grid-cols-3 gap-2 text-xs text-zinc-600 dark:text-zinc-300">
                                        <div class="flex items-center gap-1.5">
                                            <flux:icon name="arrows-pointing-out" class="size-4 text-zinc-500" />
                                            <span>{{ $property->size ? number_format((int) $property->size, 0, ',', '.') . ' m²' : 'N/A' }}</span>
                                        </div>

                                        <div class="flex items-center gap-1.5">
                                            <flux:icon name="home" class="size-4 text-zinc-500" />
                                            <span>{{ $property->rooms ? (int) $property->rooms . ' rooms' : 'N/A' }}</span>
                                        </div>

                                        <div class="flex items-center gap-1.5">
                                            <flux:icon name="users" class="size-4 text-zinc-500" />
                                            <span>{{ $property->bathrooms ? (int) $property->bathrooms . ' baths' : 'N/A' }}</span>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        @foreach (($property->characteristics ?? []) as $characteristic)
                                            <flux:badge size="sm" color="zinc">
                                                {{ $characteristic }}
                                            </flux:badge>
                                        @endforeach
                                    </div>
                                </flux:card>
                            </div>
                        </flux:modal.trigger>

                        <flux:modal name="{{ $modalName }}" variant="flyout" class="md:max-w-3xl">
                            <div class="space-y-6 p-1">
                                <div x-data="{ activePhoto: 0, photos: @js($galleryImages) }" class="space-y-3">
                                    <div class="aspect-video overflow-hidden rounded-xl shadow-sm">
                                        <img
                                            :src="photos[activePhoto]"
                                            alt="{{ $property->title ?? 'Main property image' }}"
                                            class="h-full w-full object-cover"
                                            loading="lazy"
                                        />
                                    </div>

                                    <div class="grid grid-cols-2 gap-2">
                                        @foreach ($galleryImages as $index => $thumbnail)
                                            <button
                                                type="button"
                                                @click="activePhoto = {{ $index }}"
                                                class="overflow-hidden rounded-lg border border-transparent shadow-sm transition"
                                                :class="activePhoto === {{ $index }} ? 'border-blue-500 opacity-100' : 'opacity-70 hover:opacity-100'"
                                            >
                                                <img
                                                    src="{{ $thumbnail }}"
                                                    alt="Property thumbnail {{ $index + 1 }}"
                                                    class="h-20 w-full object-cover"
                                                    loading="lazy"
                                                />
                                            </button>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="space-y-2 border-b border-zinc-200 pb-4 dark:border-zinc-800">
                                    <flux:heading size="lg">{{ $property->title ?? 'Property details' }}</flux:heading>
                                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400">
                                        €{{ number_format((int) ($property->price ?? 0), 0, ',', '.') }}
                                    </p>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                    <div class="space-y-4 md:col-span-2">
                                        <flux:heading size="sm">About this property</flux:heading>
                                        <p class="whitespace-pre-line text-sm leading-7 text-zinc-700 dark:text-zinc-300">
                                            {{ $property->description ?? 'No detailed description available.' }}
                                        </p>

                                        <div class="space-y-3">
                                            <flux:heading size="sm">Features & Amenities</flux:heading>
                                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                                @forelse (($property->characteristics ?? []) as $characteristic)
                                                    <flux:badge color="zinc" size="sm" class="inline-flex items-center gap-1.5">
                                                        <flux:icon name="sparkles" class="size-3.5" />
                                                        <span>{{ ucfirst($characteristic) }}</span>
                                                    </flux:badge>
                                                @empty
                                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">No amenities listed.</p>
                                                @endforelse
                                            </div>
                                        </div>

                                        @if (is_string($property->surreal_reason ?? null) && trim($property->surreal_reason) !== '')
                                            <div class="space-y-2 rounded-xl border border-zinc-200 p-3 dark:border-zinc-800">
                                                <flux:heading size="sm">AI Insights</flux:heading>
                                                <p class="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                                    <flux:icon name="sparkles" class="mt-0.5 size-4 text-violet-500" />
                                                    <span>{{ $property->surreal_reason }}</span>
                                                </p>
                                            </div>
                                        @endif
                                    </div>

                                    <flux:card class="h-fit p-4" variant="subtle">
                                        <div class="space-y-3 text-sm">
                                            <div class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300">
                                                <flux:icon name="arrows-pointing-out" class="size-4 text-zinc-500" />
                                                <span><strong>Size:</strong> {{ $property->size ? number_format((int) $property->size, 0, ',', '.') . ' m²' : 'N/A' }}</span>
                                            </div>
                                            <div class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300">
                                                <flux:icon name="home" class="size-4 text-zinc-500" />
                                                <span><strong>Rooms:</strong> {{ $property->rooms ?? 'N/A' }}</span>
                                            </div>
                                            <div class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300">
                                                <flux:icon name="user-group" class="size-4 text-zinc-500" />
                                                <span><strong>Bathrooms:</strong> {{ $property->bathrooms ?? 'N/A' }}</span>
                                            </div>
                                            <div class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300">
                                                <flux:icon name="building-office-2" class="size-4 text-zinc-500" />
                                                <span><strong>Type:</strong> {{ $property->type ?? 'N/A' }}</span>
                                            </div>
                                            <div class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300">
                                                <flux:icon name="check-badge" class="size-4 text-zinc-500" />
                                                <span><strong>State:</strong> {{ $property->state ?? 'N/A' }}</span>
                                            </div>
                                        </div>
                                    </flux:card>
                                </div>

                                <div class="flex flex-col gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-800 sm:flex-row sm:justify-end">
                                    <flux:button variant="ghost">
                                        Contact Agent
                                    </flux:button>
                                    <flux:button
                                        href="{{ $property->url }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        variant="primary"
                                    >
                                        View Original
                                    </flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    </div>
                @empty
                    <div class="md:col-span-2 xl:col-span-3">
                        <flux:card class="p-8 text-center">
                            <flux:heading size="md">No properties found</flux:heading>
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Try broadening your filters.</p>
                        </flux:card>
                    </div>
                @endforelse
            </div>

            <flux:card class="mt-8 p-3">
                <div class="flex items-center justify-between gap-3">
                    <flux:button
                        variant="outline"
                        icon="chevron-left"
                        wire:click="previousPage"
                        :disabled="$this->properties->onFirstPage()"
                    >
                        Previous
                    </flux:button>

                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-300">
                        Page {{ $this->properties->currentPage() }} of {{ $this->properties->lastPage() }}
                    </flux:text>

                    <flux:button
                        variant="outline"
                        icon-trailing="chevron-right"
                        wire:click="nextPage"
                        :disabled="! $this->properties->hasMorePages()"
                    >
                        Next
                    </flux:button>
                </div>
            </flux:card>
        </main>
    </div>
</section>
