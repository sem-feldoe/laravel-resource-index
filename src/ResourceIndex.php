<?php

declare(strict_types=1);

namespace Atx\ResourceIndex;

use Atx\ResourceIndex\Contracts\ResourceIndex as ResourceIndexContract;
use App\Enums\SupportedLocale;
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

class ResourceIndex implements ResourceIndexContract
{
    protected Model $model;

    protected ResourceCollection|JsonResource|string $resourceClassName;

    protected BuilderContract $query;

    protected int $perPage = 15;

    protected bool $nested = false;

    protected bool $withPagination = false;

    protected string $defaultSortColumn = 'id';

    protected string $defaultSortDirection = 'asc';

    /**
     * @throws NotAModelClassException
     */
    public function from(Model|string $model, ResourceCollection|JsonResource|string $resource): self
    {
        if (is_string($model)) {
            $model = app($model);
        }

        if (! is_string($resource)) {
            $resource = get_class($resource);
        }

        if (! is_a($model, Model::class)) {
            throw NotAModelClassException::of($model);
        }

        $this->model = $model;

        $this->resourceClassName = $resource;

        $this->init();

        return $this;
    }

    public function filter(array $filters): self
    {
        foreach ($filters as $filter => $value) {
            if (is_array($value)) {
                $this->query->whereIn($filter, $value);
            } elseif (is_null($value)) {
                $this->query->whereNull($filter);
            } else {
                $this->query->where($filter, $value);
            }
        }

        return $this;
    }

    public function published(): self
    {
        $this->query->where('active', true)
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where('publish_down', '>=', now())
                        ->orWhereNull('publish_down');
                })->where('publish_up', '<=', now());
            });

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
     *
     * @throws BindingResolutionException|NotAResourceClassException
     */
    public function processRequest(
        Request $request,
        array $filterable = [],
        array $searchable = [],
        array $orderable = []
    ): self {
        if ($request->has('nested')) {
            $this->nested = (bool) $request->get('nested', false);
        }

        if (($usingPagination = $request->boolean('pagination', false)) && $usingPagination === true) {
            $this->withPagination = true;
        }

        $this->processPagination($request->get('perPage'));

        $this->processFilters($request->get('filter', []), $filterable);

        $this->processSearch($request->get('search'), $searchable);

        $this->processOrders($request->get('sort'), $orderable);

        return $this;
    }

    /**
     * @throws NotAResourceClassException
     */
    public function response(): JsonResponse
    {
        if ($this->withPagination) {
            $query = $this->query->fastPaginate($this->perPage);
        } else {
            $query = $this->query->get();
        }
        if (in_array(CollectsResources::class, class_uses_recursive($this->resourceClassName))) {
            // We have a resource collection
            $resource = $this->resourceClassName::make($query);
        } else {
            // We have a resource item
            $resource = $this->resourceClassName::collection($query);
        }
        if (! is_a($resource, JsonResource::class)) {
            throw NotAResourceClassException::of($resource);
        }

        return $resource->response();
    }

    private function init(): void
    {
        $this->query = $this->model->newQuery()->select($this->model->getTable().'.*');
    }

    /**
     * override default query
     */
    public function useQuery(BuilderContract $query): self
    {
        $this->query = $query;

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

    protected function processPagination(?int $perPage): void
    {
        if (! is_null($perPage)) {
            $this->perPage = $perPage;
        }
    }

    protected function processFilters(array $filters, array $filterable): void
    {
        foreach ($filters as $filter => $value) {
            // Special id filter
            if ($filter == 'id') {
                if (! is_array($value)) {
                    $value = [$value];
                }
                $this->query->whereIn('id', $value);
                continue;
            }

            if (! array_key_exists($filter, $filterable)) {
                continue;
            }
            $filter = $filterable[$filter];
            if (str_contains($filter, '.')) {
                // relation filter
                [$relation, $filter] = explode('.', $filter, 2);
                $this->query->whereHas($relation, function ($query) use ($filter, $value) {
                    if (is_array($value)) {
                        $query->whereIn($filter, $value);
                    } else {
                        $query->where($filter, $value);
                    }
                });
            } else {
                if (is_array($value)) {
                    $this->query->whereIn($filter, $value);
                } else {
                    $this->query->where($filter, $value);
                }
            }
        }

        if ($this->nested) {
            $this->query->whereNull($this->materializeColumnName('parent_id'))->with('children');
        }
    }

    protected function processSearch(?string $search, array $searchable): void
    {
        $search = trim((string) $search);
        if (empty($search)) {
            return;
        }

        $this->query->where(function ($query) use ($search, $searchable) {
            foreach ($searchable as $column) {
                if (is_callable($column)) {
                    $column($this, $query, $search);
                } else {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            }
        });
    }

    /**
     * @throws BindingResolutionException|NotAResourceClassException
     */
    protected function processOrders(?string $sort, array $orderable = []): void
    {
        if (method_exists($this->query, 'ordered')) {
            $this->query->ordered();
        }

        if (is_null($sort)) {
            $this->query->orderBy($this->defaultSortColumn, $this->defaultSortDirection);

            return;
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
            if (! array_key_exists($sortColumn, $orderable) && ! in_array($sortColumn, $orderable)) {
                continue;
            }

            if (isset($orderable[$sortColumn]) && is_callable($orderable[$sortColumn])) {
                $orderable[$sortColumn]($this->query, $direction);
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
            if (Str::endsWith($sortColumn, SupportedLocale::suffixes())) {
                [$sortColumn, $locale] = explode(':', $sortColumn, 2);
                try {
                    $this->orderByTranslation($locale, $sortColumn, $direction);
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

    public function whereHas(string $relation, Closure $callback = null, string $operator = '>=', int $count = 1): self
    {
        $this->query->whereHas($relation, $callback, $operator, $count);

        return $this;
    }

    /**
     * @throws BindingResolutionException
     * @throws NotAResourceClassException
     */
    protected function orderByTranslation(
        string $locale,
        string $translationField,
        string $sortMethod = 'asc'
    ): BuilderContract {
        if (! class_exists(\App\Enums\SupportedLocale::class) ||
            ! method_exists($this->model, 'getTranslationModelName') ||
            ! method_exists($this->model, 'getLocaleKey')
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
}
