<?php

declare(strict_types=1);

namespace Atx\ResourceIndex;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Laravel\Scout\Builder as ScoutBuilder;

final class FilterManager
{
    /**
     * @throws \Exception
     */
    public function filter(
        EloquentBuilder|ScoutBuilder|null $query,
        array $filters
    ): void {
        if (is_null($query)) {
            throw new \Exception('Query not set yet');
        }

        foreach ($filters as $filter => $value) {
            if (is_array($value)) {
                $query->whereIn($filter, $value);
            } elseif (is_null($value)) {
                $query->whereNull($filter);
            } else {
                $query->where($filter, $value);
            }
        }
    }

    public function processFilters(
        EloquentBuilder|ScoutBuilder|null $query,
        array $filters,
        array $filterable
    ): void {
        if (is_null($query)) {
            throw new \Exception('Query not set yet');
        }

        foreach ($filters as $filter => $value) {
            // Special id filter
            if ($filter == 'id') {
                if (! is_array($value)) {
                    $value = [$value];
                }
                $query->whereIn('id', $value);

                continue;
            }

            if (! array_key_exists($filter, $filterable)) {
                continue;
            }
            $filter = $filterable[$filter];

            if (is_callable($filter)) {
                $query = $filter($this, $query, $value);
                continue;
            }

            if (str_contains($filter, '.')) {
                // relation filter
                [$relation, $filter] = explode('.', $filter, 2);
                $query->whereHas($relation, function ($query) use ($filter, $value) {
                    if (is_array($value)) {
                        $query->whereIn($filter, $value);
                    } else {
                        $query->where($filter, $value);
                    }
                });
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($filter, $value);
            } else {
                $query->where($filter, $value);
            }
        }
    }
}
