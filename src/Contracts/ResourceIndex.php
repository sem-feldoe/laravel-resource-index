<?php

declare(strict_types=1);

namespace Atx\ResourceIndex\Contracts;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

interface ResourceIndex
{
    public function from(Model|string $model, ResourceCollection|JsonResource|string $resource): self;

    public function filter(array $filters): self;

    public function secured(): self;

    public function published(): self;

    public function processRequest(
        Request $request,
        array $filterable = [],
        array $searchable = [],
        array $orderable = []
    ): self;

    public function response(): JsonResponse;

    public function useQuery(BuilderContract $query): self;

    public function with(array $relations, Closure|string|null $callback = null): self;

    public function usingPagination(): self;

    public function whereHas(string $relation, Closure $callback = null, string $operator = '>=', int $count = 1): self;

    public function materializeColumnName(string $column): string;

    public function setDefaultOrder(string $column, string $direction = 'asc'): self;

    public function withTrashed(): self;
}
