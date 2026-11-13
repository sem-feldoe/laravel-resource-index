<?php

declare(strict_types=1);

namespace Atx\ResourceIndex\Wayfinder;

use Atx\ResourceIndex\Attributes\ResourceIndexResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Laravel\Ranger\Components\JsonResponse;
use Laravel\Ranger\Components\ResourceResponse;
use Laravel\Ranger\Components\Route;
use Laravel\Surveyor\Analyzer\ResourceAnalyzer;
use Laravel\Surveyor\Types\ArrayType;
use Laravel\Surveyor\Types\Entities\ResourceResponse as AnalyzedResourceResponse;
use Laravel\Surveyor\Types\Type;
use RuntimeException;

final class DescribeResponse
{
    public function __construct(private readonly ResourceAnalyzer $analyzer) {}

    public function __invoke(Route $route): void
    {
        if (! $route->hasController()) {
            return;
        }
        $controller = $route->controller();
        if (! class_exists($controller)) {
            return;
        }
        $definition = ResourceIndexResponse::forController($controller, $route->method());
        if ($definition === null) {
            return;
        }
        if (is_a($definition->resource, ResourceCollection::class, true)) {
            throw new RuntimeException('Wayfinder integration currently requires an item JsonResource, not a ResourceCollection.');
        }
        $resource = $this->analyzer->buildResourceResponse($definition->resource);
        if (! $resource instanceof AnalyzedResourceResponse || ! $resource->data instanceof ArrayType) {
            throw new RuntimeException("Unable to infer {$definition->resource} for {$route->controller()}::{$route->method()}.");
        }

        $wrap = AnonymousResourceCollection::$wrap;
        $payload = [
            $wrap ?? 'data' => Type::arrayShape(Type::int(), $resource->data),
            'meta' => Type::arrayShape(Type::string(), Type::mixed())->optional(),
            'links' => Type::arrayShape(Type::string(), Type::mixed())->optional(),
        ];
        $responses = [new JsonResponse($payload)];
        if ($wrap === null) {
            $responses[] = new ResourceResponse($definition->resource, $resource->data->value, true, null);
        }
        $route->setPossibleResponses($responses);
    }
}
