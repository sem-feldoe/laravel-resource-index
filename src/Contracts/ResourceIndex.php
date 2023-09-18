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
use Laravel\Scout\Builder as ScoutBuilderContract;

interface ResourceIndex
{
    public function from(Model|string $model, ResourceCollection|JsonResource|string $resource, Request $request = null): self;

    public function filter(array $filters): self;

    public function secured(): self;

    public function published(string $startColumn = 'publish_up', string $endColumn = 'publish_down'): self;

    public function processRequest(
        Request $request,
        array $filterable = [],
        array $searchable = [],
        array $orderable = []
    ): self;

    public function allowedFilters(array $filters): self;

    public function allowedSearchColumn(array $columns): self;

    public function allowedSorts(array $sorts, string $defaultSort = null, string $defaultSortDirection = 'asc'): self;

    public function response(): JsonResponse;

    public function useQuery(BuilderContract|ScoutBuilderContract $query): self;

    public function with(array $relations, Closure|string $callback = null): self;

    public function withCount(array $relations): self;

    public function usingPagination(): self;

    public function whereHas(string $relation, Closure $callback = null, string $operator = '>=', int $count = 1): self;

    public function materializeColumnName(string $column): string;

    public function setDefaultOrder(string $column, string $direction = 'asc'): self;

    public function withTrashed(): self;

    public function additional(array $data): self;
}
