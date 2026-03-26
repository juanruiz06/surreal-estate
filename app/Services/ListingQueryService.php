<?php

namespace App\Services;

use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListingQueryService
{
    public function getAllCharacteristics(): array
    {
        return Listing::query()
            ->whereNotNull('characteristics')
            ->pluck('characteristics')
            ->flatten()
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function getProperties(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        $allowedSortFields = ['id', 'price', 'size', 'surreal_score'];
        $sortBy = in_array($filters['sortBy'] ?? 'price', $allowedSortFields, true) ? $filters['sortBy'] : 'price';
        $sortDirection = ($filters['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return Listing::query()
            ->when(
                is_string($filters['search'] ?? null) && trim($filters['search']) !== '',
                function ($query) use ($filters): void {
                    $search = trim((string) $filters['search']);
                    $query->where(function ($innerQuery) use ($search): void {
                        $innerQuery
                            ->where('title', 'like', '%'.$search.'%')
                            ->orWhere('description', 'like', '%'.$search.'%')
                            ->orWhere('neighborhood', 'like', '%'.$search.'%');
                    });
                }
            )
            ->when(is_numeric($filters['minPrice'] ?? null), fn ($query) => $query->where('price', '>=', (int) $filters['minPrice']))
            ->when(is_numeric($filters['maxPrice'] ?? null), fn ($query) => $query->where('price', '<=', (int) $filters['maxPrice']))
            ->when(is_string($filters['type'] ?? null) && trim((string) $filters['type']) !== '', fn ($query) => $query->where('type', trim((string) $filters['type'])))
            ->when(is_string($filters['state'] ?? null) && trim((string) $filters['state']) !== '', fn ($query) => $query->where('state', trim((string) $filters['state'])))
            ->when(
                is_array($filters['selectedNeighborhoods'] ?? null) && count($filters['selectedNeighborhoods']) > 0,
                fn ($query) => $query->whereIn('neighborhood', $filters['selectedNeighborhoods'])
            )
            ->when(is_numeric($filters['minSize'] ?? null), fn ($query) => $query->where('size', '>=', (int) $filters['minSize']))
            ->when(is_numeric($filters['maxSize'] ?? null), fn ($query) => $query->where('size', '<=', (int) $filters['maxSize']))
            ->when(is_numeric($filters['minRooms'] ?? null), fn ($query) => $query->where('rooms', '>=', (int) $filters['minRooms']))
            ->when(is_numeric($filters['minBathrooms'] ?? null), fn ($query) => $query->where('bathrooms', '>=', (int) $filters['minBathrooms']))
            ->when(
                is_array($filters['selectedCharacteristics'] ?? null) && count($filters['selectedCharacteristics']) > 0,
                function ($query) use ($filters): void {
                    foreach ($filters['selectedCharacteristics'] as $characteristic) {
                        if (is_string($characteristic) && trim($characteristic) !== '') {
                            $query->whereJsonContains('characteristics', trim($characteristic));
                        }
                    }
                }
            )
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);
    }

    public function getSmartSearchPreviewCount(array $filters): int
    {
        return Listing::query()
            ->when(is_numeric($filters['minPrice'] ?? null), fn ($query) => $query->where('price', '>=', (int) $filters['minPrice']))
            ->when(is_numeric($filters['maxPrice'] ?? null), fn ($query) => $query->where('price', '<=', (int) $filters['maxPrice']))
            ->when(is_string($filters['type'] ?? null) && trim((string) $filters['type']) !== '', fn ($query) => $query->where('type', trim((string) $filters['type'])))
            ->when(is_numeric($filters['minRooms'] ?? null), fn ($query) => $query->where('rooms', '>=', (int) $filters['minRooms']))
            ->when(is_numeric($filters['minSize'] ?? null), fn ($query) => $query->where('size', '>=', (int) $filters['minSize']))
            ->when(is_numeric($filters['maxSize'] ?? null), fn ($query) => $query->where('size', '<=', (int) $filters['maxSize']))
            ->when(
                is_array($filters['neighborhoods'] ?? null) && count($filters['neighborhoods']) > 0,
                fn ($query) => $query->whereIn('neighborhood', $filters['neighborhoods'])
            )
            ->when(
                is_string($filters['searchKeyword'] ?? null) && trim((string) $filters['searchKeyword']) !== '',
                function ($query) use ($filters): void {
                    $keyword = trim((string) $filters['searchKeyword']);
                    $query->where(function ($innerQuery) use ($keyword): void {
                        $innerQuery
                            ->where('title', 'like', '%'.$keyword.'%')
                            ->orWhere('description', 'like', '%'.$keyword.'%')
                            ->orWhere('neighborhood', 'like', '%'.$keyword.'%');
                    });
                }
            )
            ->when(
                is_array($filters['characteristics'] ?? null) && count($filters['characteristics']) > 0,
                function ($query) use ($filters): void {
                    foreach ($filters['characteristics'] as $characteristic) {
                        if (is_string($characteristic) && trim($characteristic) !== '') {
                            $query->whereJsonContains('characteristics', trim($characteristic));
                        }
                    }
                }
            )
            ->count();
    }

    public function getLifestylePreviewCount(array $filters): int
    {
        return Listing::query()
            ->when(
                is_array($filters['neighborhoods'] ?? null) && count($filters['neighborhoods']) > 0,
                fn ($query) => $query->whereIn('neighborhood', $filters['neighborhoods'])
            )
            ->when(is_numeric($filters['maxPrice'] ?? null), fn ($query) => $query->where('price', '<=', (int) $filters['maxPrice']))
            ->when(is_numeric($filters['minRooms'] ?? null), fn ($query) => $query->where('rooms', '>=', (int) $filters['minRooms']))
            ->when(is_numeric($filters['minSize'] ?? null), fn ($query) => $query->where('size', '>=', (int) $filters['minSize']))
            ->when(is_string($filters['type'] ?? null) && trim((string) $filters['type']) !== '', fn ($query) => $query->where('type', trim((string) $filters['type'])))
            ->when(
                is_string($filters['searchKeyword'] ?? null) && trim((string) $filters['searchKeyword']) !== '',
                function ($query) use ($filters): void {
                    $keyword = trim((string) $filters['searchKeyword']);
                    $query->where(function ($innerQuery) use ($keyword): void {
                        $innerQuery
                            ->where('title', 'like', '%'.$keyword.'%')
                            ->orWhere('description', 'like', '%'.$keyword.'%')
                            ->orWhere('neighborhood', 'like', '%'.$keyword.'%');
                    });
                }
            )
            ->when(
                is_array($filters['characteristics'] ?? null) && count($filters['characteristics']) > 0,
                function ($query) use ($filters): void {
                    foreach ($filters['characteristics'] as $characteristic) {
                        if (is_string($characteristic) && trim($characteristic) !== '') {
                            $query->whereJsonContains('characteristics', trim($characteristic));
                        }
                    }
                }
            )
            ->count();
    }
}
