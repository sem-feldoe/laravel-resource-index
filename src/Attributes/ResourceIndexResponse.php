<?php

declare(strict_types=1);

namespace Atx\ResourceIndex\Attributes;

use Attribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionMethod;

#[Attribute(Attribute::TARGET_METHOD)]
final class ResourceIndexResponse
{
    /** @param class-string<JsonResource> $resource */
    public function __construct(public readonly string $resource)
    {
        if (! is_a($resource, JsonResource::class, true)) {
            throw new InvalidArgumentException("{$resource} must extend JsonResource.");
        }
    }

    /** @param class-string $controller */
    public static function forController(string $controller, string $method): ?self
    {
        if (! method_exists($controller, $method)) {
            return null;
        }
        $attributes = (new ReflectionMethod($controller, $method))->getAttributes(self::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    public static function forRequest(Request $request): ?self
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            return null;
        }
        $callback = $route->getAction('uses');
        if (! is_string($callback)) {
            return null;
        }
        [$controller, $method] = Str::parseCallback($callback, '__invoke');
        if ($controller === null || $method === null || ! class_exists($controller)) {
            return null;
        }

        return self::forController($controller, $method);
    }
}
