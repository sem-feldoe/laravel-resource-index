<?php

declare(strict_types=1);

namespace Atx\ResourceIndex;

use App\Enums\SupportedLocale;
use Atx\ResourceIndex\Contracts\ResourceIndex as ResourceIndexContract;
use Atx\ResourceIndex\Exceptions\MissingTranslationRequirementsException;
use Atx\ResourceIndex\Exceptions\NotAModelClassException;
use Atx\ResourceIndex\Exceptions\NotAResourceClassException;
use Closure;
use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\CollectsResources;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;
use Laravel\Scout\Builder as ScoutBuilderContract;

class ResourceIndex implements ResourceIndexContract
{
    protected Model $model;

    protected ResourceCollection|JsonResource|string $resourceClassName;

    protected BuilderContract|ScoutBuilderContract|null $query = null;

    protected FilterManager $filterManager;

    protected PaginationManager $paginationManager;

    protected int $perPage = 15;

    protected bool $nested = false;

    protected bool $withPagination = false;

    protected string $defaultSortColumn = 'id';

    protected string $defaultSortDirection = 'asc';

    protected bool $isMultilingual = false;

    protected ?int $limit = null;

    protected ?int $offset = null;

    protected ?Request $request = null;

    protected bool $sortProcessed = false;

    protected array $additional = [];

    public function __construct()
    {
        $this->filterManager = new FilterManager;
        $this->paginationManager = new PaginationManager;
    }

    /**
     * @throws NotAModelClassException
     */
    public function from(Model|string $model, ResourceCollection|JsonResource|string $resource, ?Request $request = null): self
    {
        $this->model = $this->getModelClass($model);
        $this->resourceClassName = $this->getResourceClass($resource);

        $this->initializeProperties();

        if (! is_null($request)) {
            try {
                $this->processRequest($request);
            } catch (NotAResourceClassException) {
            }
        }

        return $this;
    }

    public function filter(array $filters): self
    {
        $this->filterManager->filter($this->query, $filters);

        return $this;
    }

    public function published(string $startColumn = 'publish_up', string $endColumn = 'publish_down'): self
    {
        $this->query->where('active', true)
            ->where(fn (Builder $query) => $query->where(fn (Builder $subQuery) => $subQuery->where($endColumn, '>=', now())
                ->orWhereNull($endColumn)
            )
                ->where($startColumn, '<=', now())
            );

        return $this;
    }

    public function setDefaultOrder(string $column, string $direction = 'asc'): self
    {
        $this->defaultSortColumn = $column;
        $this->defaultSortDirection = $direction;

        return $this;
    }

    public function secured(): self
    {
        if (method_exists($this->query, 'active')) {
            $this->query->active();
        }

        return $this;
    }

    /**
     * @throws BindingResolutionException|NotAResourceClassException
     */
    public function processRequest(
        Request $request,
        array $filterable = [],
        array $searchable = [],
        array $orderable = []
    ): self {
        if ($request->has('nested')) {
            $this->nested = $request->boolean('nested', false);
        }

        if (($usingPagination = $request->boolean('pagination', false)) && $usingPagination === true) {
            $this->withPagination = true;
        } else {
            if ($request->has('limit')) {
                $this->limit = $request->integer('limit');
            }
            if ($request->has('offset')) {
                $this->offset = $request->integer('offset');
            }
        }

        if ($request->has('perPage')) {
            $this->perPage = $request->integer('perPage');
        }

        if (! empty($filterable)) {
            $this->filterManager->processFilters($this->query, $request->get('filter', []), $filterable); // Extracted to FilterManager
        }

        if (! empty($searchable)) {
            $this->processSearch($this->getQuerySearch(), $searchable);
        }

        if (! empty($orderable)) {
            $this->processSorts($request->get('sort'), $orderable);
        }

        $this->request = $request;

        return $this;
    }

    public function withTrashed(): self
    {
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses($this->model))) {
            $this->query->withTrashed(); // @phpstan-ignore-line
        }

        return $this;
    }

    public function additional(array $data): self
    {
        $this->additional = $data;

        return $this;
    }

    /**
     * @throws NotAResourceClassException
     */
    public function response(): JsonResponse
    {
        if (! $this->sortProcessed) {
            $this->processSorts(null);
        }

        $this->manageNestedQuery();

        return $this->composeResponse($this->executeQuery());
    }

    private function manageNestedQuery(): void
    {
        if ($this->nested) {
            $this->query->whereNull($this->materializeColumnName('parent_id'))->with('children');
        }
    }

    private function executeQuery(): \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection
    {
        if ($this->withPagination) {
            return $this->paginationManager->paginate($this->query, $this->perPage);
        }

        $this->paginationManager->applyLimitOffset($this->query, $this->limit, $this->offset);

        return $this->query->get();
    }

    private function initializeProperties(): void
    {
        $this->sortProcessed = false;
        $this->additional = [];
        $this->nested = false;
        $this->withPagination = false;
        $this->defaultSortColumn = 'id';
        $this->defaultSortDirection = 'asc';
        $this->isMultilingual = false;
        $this->limit = null;
        $this->offset = null;
        $this->request = null;
        $this->init();
    }

    private function init(): void
    {
        $this->isMultilingual = enum_exists(\App\Enums\SupportedLocale::class)
            && method_exists(\App\Enums\SupportedLocale::class, 'suffixes') // @phpstan-ignore-line
            && count(\App\Enums\SupportedLocale::suffixes()) >= 2;
        if (is_null($this->query)) {
            $this->query = $this->model->newQuery()->select($this->model->getTable().'.*');
        }
    }

    private function getModelClass($model): Model
    {
        if (is_string($model)) {
            $model = app($model);
        }
        if (! is_a($model, Model::class)) {
            throw NotAModelClassException::of($model);
        }

        return $model;
    }

    private function getResourceClass($resource): string
    {
        if (! is_string($resource)) {
            $resource = get_class($resource);
        }

        return $resource;
    }

    public function useQuery(BuilderContract|ScoutBuilderContract $query): self
    {
        $this->query = $query;
        $this->sortProcessed = false;

        return $this;
    }

    public function materializeColumnName(string $column): string
    {
        if (str_contains($column, '.')) {
            [$table, $column] = explode('.', $column, 2);
        } else {
            $table = $this->model->getTable();
        }

        return $table.'.'.$column;
    }

    public function with(array $relations, Closure|string|null $callback = null): self
    {
        $this->query->with($relations, $callback);

        return $this;
    }

    public function withCount(array $relations): self
    {
        $this->query->withCount($relations);

        return $this;
    }

    public function allowedRelations(array $relations): self
    {
        $this->handleAllowedRelations($relations, 'with', [$this, 'with']);
        $this->handleAllowedRelations($relations, 'count', [$this, 'withCount']);

        return $this;
    }

    public function allowedFilters(array $filters): self
    {
        try {
            $this->filterManager->processFilters($this->query, $this->getRequest()->get('filter', []), $filters);
        } catch (Exception) {
        }

        return $this;
    }

    public function allowedSearchColumn(array $columns): self
    {
        $this->processSearch($this->getQuerySearch(), $columns);

        return $this;
    }

    public function allowedSorts(array $sorts, ?string $defaultSort = null, string $defaultSortDirection = 'asc'): self
    {
        if (! is_null($defaultSort)) {
            $this->setDefaultOrder($defaultSort, $defaultSortDirection);
        }

        try {
            $this->processSorts($this->getRequest()->get('sort'), $sorts);
        } catch (NotAResourceClassException) {
        }

        return $this;
    }

    protected function processSearch(?string $search, array $searchable): void
    {
        $search = trim((string) $search);
        if (empty($search)) {
            return;
        }

        if ($this->query instanceof ScoutBuilderContract) {
            $this->query->query = $search;

            return;
        }

        $this->query->where(function ($query) use ($search, $searchable) {
            foreach ($searchable as $column) {
                if (is_callable($column)) {
                    $column($this, $query, $search);
                } else {
                    if (str_contains($column, '.')) {
                        [$relation, $column] = explode('.', $column, 2);
                        $query->orWhereHas(
                            $relation,
                            fn ($relationQuery) => $relationQuery->where($column, 'like', '%'.$search.'%')
                        );
                    } else {
                        $query->orWhere($column, 'like', '%'.$search.'%');
                    }
                }
            }
        });
    }

    /**
     * @throws BindingResolutionException|NotAResourceClassException
     */
    protected function processSorts(?string $sort, array $sortable = []): void
    {
        $this->sortProcessed = true;
        if (is_null($sort)) {
            if (method_exists($this->query, 'ordered')) {
                $this->query->ordered();
            }

            $sort = ($this->defaultSortDirection === 'desc' ? '-' : '').$this->defaultSortColumn;
        }

        $sort = explode(',', $sort);
        foreach ($sort as $sortColumn) {
            if (empty($sortColumn)) {
                continue;
            }

            $direction = 'asc';
            if ($sortColumn[0] === '-') {
                $direction = 'desc';
                $sortColumn = ltrim($sortColumn, '-');
            }
            if (! array_key_exists($sortColumn, $sortable) && ! in_array($sortColumn, $sortable)) {
                continue;
            }

            if (isset($sortable[$sortColumn]) && is_callable($sortable[$sortColumn])) {
                $sortable[$sortColumn]($this->query, $direction, $this);

                continue;
            }

            // Relation
            if (Str::contains($sortColumn, '.')) {
                [$relation, $sortColumn] = explode('.', $sortColumn, 2);
                $relation = $this->model->{Str::camel($relation)}();
                if ($relation instanceof BelongsTo) {
                    $relatedModel = $relation->getRelated();
                    $relatedKey = $relatedModel->getKeyName();
                    $foreignColumn = $relation->getForeignKeyName();
                    $this->query->join(
                        $relatedModel->getTable(),
                        $this->model->getTable().'.'.$foreignColumn,
                        '=',
                        $relatedModel->getTable().'.'.$relatedKey
                    );
                    $this->query->orderBy($relatedModel->getTable().'.'.$sortColumn, $direction);
                }

                continue;
            }
            if ($this->isMultilingual
                && Str::endsWith($sortColumn, SupportedLocale::suffixes()) // @phpstan-ignore-line
            ) {
                [$sortColumn, $locale] = explode(':', $sortColumn, 2);

                try {
                    $this->sortByTranslation($locale, $sortColumn, $direction);
                } catch (Exception) {
                }
            } else {
                $this->query->orderBy($sortColumn, $direction);
            }
        }
    }

    public function usingPagination(): self
    {
        $this->withPagination = true;

        return $this;
    }

    public function whereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1): self
    {
        $this->query->whereHas($relation, $callback, $operator, $count);

        return $this;
    }

    /**
     * @throws BindingResolutionException|\Atx\ResourceIndex\Exceptions\NotAResourceClassException|\Atx\ResourceIndex\Exceptions\MissingTranslationRequirementsException
     */
    protected function sortByTranslation(
        string $locale,
        string $translationField,
        string $sortMethod = 'asc'
    ): BuilderContract {
        if (! $this->isMultilingual || ! method_exists($this->model, 'getTranslationModelName')
            || ! method_exists($this->model, 'getLocaleKey')
        ) {
            throw new MissingTranslationRequirementsException;
        }
        $translationTable = app()->make($this->model->getTranslationModelName())->getTable();
        $localeKey = $this->model->getLocaleKey();
        $table = $this->model->getTable();
        $keyName = $this->model->getKeyName();

        return $this->query
            ->with('translations')
            ->select("{$table}.*")
            ->leftJoin(
                $translationTable,
                function (JoinClause $join) use ($translationTable, $localeKey, $table, $keyName, $locale) {
                    if (! method_exists($this->model, 'getTranslationRelationKey')) {
                        return;
                    }
                    $join->on(
                        "{$translationTable}.{$this->model->getTranslationRelationKey()}",
                        '=',
                        "{$table}.{$keyName}"
                    )->where("{$translationTable}.{$localeKey}", $locale);
                }
            )
            ->orderBy("{$translationTable}.{$translationField}", $sortMethod);
    }

    protected function getRequest(): Request
    {
        return $this->request ?: request();
    }

    protected function getQuerySearch(): ?string
    {
        $request = $this->getRequest();
        if ($request->has('search')) {
            return $request->get('search');
        } elseif ($request->has('query')) {
            return $request->get('query');
        }

        return null;
    }

    /**
     * @throws \Atx\ResourceIndex\Exceptions\NotAResourceClassException
     */
    private function composeResponse(
        \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection $query
    ): JsonResponse {
        if (in_array(CollectsResources::class, class_uses_recursive($this->resourceClassName))) {
            $resource = $this->resourceClassName::make($query);
        } else {
            $resource = $this->resourceClassName::collection($query);
        }
        if (! is_a($resource, JsonResource::class)) {
            throw NotAResourceClassException::of($resource);
        }

        if (! empty($this->additional)) {
            $resource->additional($this->additional);
        }

        return $resource->response();
    }

    private function handleAllowedRelations(array $relations, string $requestProperty, callable $callback): void
    {
        $requestedRelations = $this->getRequest()->get($requestProperty, []);
        $requestedRelations = is_array($requestedRelations) ? $requestedRelations : [$requestedRelations];

        $mappedRelations = [];
        foreach ($requestedRelations as $relation) {
            if ($this->isAssociativeArray($relations) && isset($relations[$relation])) {
                $mappedRelations[] = $relations[$relation];
            } elseif (in_array($relation, $relations)) {
                $mappedRelations[] = $relation;
            }
        }
        call_user_func($callback, $mappedRelations);
    }

    private function isAssociativeArray(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
