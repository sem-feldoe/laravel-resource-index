<?php

declare(strict_types=1);

namespace Atx\ResourceIndex\Tests;

use Atx\ResourceIndex\Tests\Fixtures\Controller;
use Atx\ResourceIndex\Tests\Fixtures\UserResource;
use Atx\ResourceIndex\Wayfinder\DescribeResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Route as HttpRoute;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route;
use Laravel\Surveyor\Analyzer\ResourceAnalyzer;
use Laravel\Surveyor\Types\Entities\ResourceResponse;
use Laravel\Surveyor\Types\Type;
use PHPUnit\Framework\TestCase;

final class WayfinderResponseTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(ResourceAnalyzer::class) || ! class_exists(Route::class)) {
            self::markTestSkipped('Optional Wayfinder dependencies are not installed.');
        }
    }

    private function route(string $method): Route
    {
        return new Route(
            new HttpRoute('GET', '/users', ['uses' => Controller::class.'@'.$method, 'controller' => Controller::class.'@'.$method]),
            new Collection, null, null
        );
    }

    public function test_only_attributed_routes_are_described(): void
    {
        $analyzer = $this->createMock(ResourceAnalyzer::class);
        $analyzer->expects(self::never())->method('buildResourceResponse');
        $route = $this->route('legacy');
        (new DescribeResponse($analyzer))($route);
        self::assertSame([], $route->possibleResponses());
    }

    public function test_resource_shape_and_optional_metadata_are_preserved(): void
    {
        $analyzer = $this->createMock(ResourceAnalyzer::class);
        $shape = Type::array(['id' => Type::int(), 'name' => Type::string()]);
        $analyzer->expects(self::once())->method('buildResourceResponse')->with(UserResource::class)
            ->willReturn(new ResourceResponse(UserResource::class, $shape));
        $route = $this->route('index');
        (new DescribeResponse($analyzer))($route);
        $response = $route->possibleResponses()[0];
        self::assertSame($shape, $response->data[AnonymousResourceCollection::$wrap ?? 'data']->valueType);
        self::assertTrue($response->data['meta']->isOptional());
        self::assertTrue($response->data['links']->isOptional());
    }

    public function test_unwrapped_collections_are_also_possible_when_wrapping_is_disabled(): void
    {
        $wrap = AnonymousResourceCollection::$wrap;
        try {
            AnonymousResourceCollection::withoutWrapping();
            $analyzer = $this->createStub(ResourceAnalyzer::class);
            $analyzer->method('buildResourceResponse')->willReturn(new ResourceResponse(UserResource::class, Type::array(['id' => Type::int()])));
            $route = $this->route('index');
            (new DescribeResponse($analyzer))($route);
            self::assertCount(2, $route->possibleResponses());
            self::assertNull($route->possibleResponses()[1]->wrap);
        } finally {
            AnonymousResourceCollection::$wrap = $wrap;
        }
    }

    public function test_unanalysable_resources_fail_generation_explicitly(): void
    {
        $analyzer = $this->createStub(ResourceAnalyzer::class);
        $analyzer->method('buildResourceResponse')->willReturn(null);
        $this->expectException(\RuntimeException::class);
        (new DescribeResponse($analyzer))($this->route('index'));
    }
}
