<?php

declare(strict_types=1);

namespace Atx\ResourceIndex;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Laravel\Scout\Builder as ScoutBuilder;

final class PaginationManager
{
    public function paginate(
        EloquentBuilder|ScoutBuilder|null $query,
        int $perPage
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        if (class_exists(\Hammerstone\FastPaginate\Hammerstone\FastPaginate::class) ||
            class_exists(\AaronFrancis\FastPaginate\FastPaginate::class)) {
            return $query->fastPaginate($perPage); // @phpstan-ignore-line
        }

        return $query->paginate($perPage);
    }

    public function applyLimitOffset(EloquentBuilder|ScoutBuilder|null $query, ?int $limit, ?int $offset): \Illuminate\Database\Eloquent\Collection
    {
        if (! is_null($limit)) {
            $query->take($limit);
        }
        if (! is_null($offset)) {
            $query->skip($limit);
        }

        return $query->get();
    }
}
