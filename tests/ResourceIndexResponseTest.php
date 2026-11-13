<?php

declare(strict_types=1);

namespace Atx\ResourceIndex\Tests;

use Atx\ResourceIndex\Attributes\ResourceIndexResponse;
use Atx\ResourceIndex\ResourceIndex;
use Atx\ResourceIndex\ResourceIndexServiceProvider;
use Atx\ResourceIndex\Tests\Fixtures\Controller;
use Atx\ResourceIndex\Tests\Fixtures\InvokableController;
use Atx\ResourceIndex\Tests\Fixtures\User;
use Atx\ResourceIndex\Tests\Fixtures\UserResource;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use InvalidArgumentException;
use Laravel\Wayfinder\WayfinderServiceProvider;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ResourceIndexResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('request', Request::create('/users'));
        $container->instance('translator', new Translator(new ArrayLoader, 'en'));
        $factory = $this->createStub(ResponseFactory::class);
        $factory->method('json')->willReturnCallback(
            fn ($data, int $status = 200, array $headers = [], int $options = 0): JsonResponse => new JsonResponse($data, $status, $headers, $options)
        );
        $container->instance(ResponseFactory::class, $factory);
        $database = new Manager($container);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $database->schema()->create('fixture_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        User::create(['name' => 'Alice']);
        User::create(['name' => 'Bob']);
    }

    protected function tearDown(): void
    {
        Manager::connection()->disconnect();
        User::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function request(string $method, bool $paginated = false): Request
    {
        $request = Request::create('/users', 'GET', $paginated ? ['pagination' => true, 'perPage' => 1] : []);
        $route = new Route('GET', '/users', ['uses' => Controller::class.'@'.$method, 'controller' => Controller::class.'@'.$method]);
        $request->setRouteResolver(fn () => $route);
        Container::getInstance()->instance('request', $request);

        return $request;
    }

    public function test_attribute_and_legacy_responses_match_with_pagination_and_additional_data(): void
    {
        foreach ([false, true] as $paginated) {
            $request = $this->request('index', $paginated);
            $actual = (new ResourceIndex)->from(User::class)->processRequest($request)
                ->additional(['summary' => ['count' => 2]])->response()->getData(true);
            $request = $this->request('legacy', $paginated);
            $legacy = (new ResourceIndex)->from(User::class, UserResource::class)->processRequest($request)
                ->additional(['summary' => ['count' => 2]])->response()->getData(true);
            self::assertSame($legacy, $actual);
            self::assertSame(['count' => 2], $actual['summary']);
            self::assertSame(['id' => 1, 'name' => 'Alice'], $actual['data'][0]);
            if ($paginated) {
                self::assertSame(2, $actual['meta']['total']);
                self::assertArrayHasKey('next', $actual['links']);
            }
        }
    }

    public function test_duplicate_declarations_are_rejected_even_when_they_match(): void
    {
        $this->request('index');
        $this->expectException(LogicException::class);
        (new ResourceIndex)->from(User::class, UserResource::class);
    }

    public function test_contradictory_declarations_are_rejected(): void
    {
        $this->request('index');
        $this->expectException(LogicException::class);
        (new ResourceIndex)->from(User::class, JsonResource::class);
    }

    public function test_missing_declaration_is_rejected(): void
    {
        $this->request('legacy');
        $this->expectException(LogicException::class);
        (new ResourceIndex)->from(User::class);
    }

    public function test_invalid_resource_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ResourceIndexResponse(User::class);
    }

    public function test_data_cannot_override_the_attribute_contract(): void
    {
        $this->request('index');
        $this->expectException(InvalidArgumentException::class);
        (new ResourceIndex)->from(User::class)->additional(['data' => []]);
    }

    public function test_legacy_additional_remains_unrestricted(): void
    {
        $this->request('legacy');
        $listing = new ResourceIndex;
        self::assertSame($listing, $listing->from(User::class, UserResource::class)->additional(['data' => []]));
    }

    public function test_explicit_request_can_resolve_the_attribute_without_a_resource_argument(): void
    {
        $request = $this->request('index');
        $this->request('legacy');
        $response = (new ResourceIndex)->from(User::class, request: $request)->response()->getData(true);
        self::assertSame([['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']], $response['data']);
    }

    public function test_provider_registers_wayfinder_only_when_available(): void
    {
        $app = new Application(dirname(__DIR__));
        $app->register(ResourceIndexServiceProvider::class);
        $app->boot();
        self::assertTrue($app->bound(\Atx\ResourceIndex\Contracts\ResourceIndex::class));
        $registered = $app->getProvider(\Atx\ResourceIndex\Wayfinder\WayfinderServiceProvider::class);
        if (class_exists(WayfinderServiceProvider::class)) {
            self::assertNotNull($registered);
        } else {
            self::assertNull($registered);
        }
    }

    public function test_wrapping_disabled_still_matches_legacy_with_and_without_additional_data(): void
    {
        $wrap = JsonResource::$wrap;
        try {
            JsonResource::withoutWrapping();
            foreach ([[], ['summary' => ['count' => 2]]] as $additional) {
                $request = $this->request('index');
                $actual = (new ResourceIndex)->from(User::class)->processRequest($request)->additional($additional)->response()->getData(true);
                $request = $this->request('legacy');
                $legacy = (new ResourceIndex)->from(User::class, UserResource::class)->processRequest($request)->additional($additional)->response()->getData(true);
                self::assertSame($legacy, $actual);
                if ($additional === []) {
                    self::assertSame(['id' => 1, 'name' => 'Alice'], $actual[0]);
                } else {
                    self::assertSame(['count' => 2], $actual['summary']);
                }
            }
        } finally {
            JsonResource::$wrap = $wrap;
        }
    }

    public function test_real_router_resolves_attributes_for_invokable_and_standard_controllers(): void
    {
        foreach ([
            InvokableController::class,
            [Controller::class, 'index'],
            Controller::class.'@index',
        ] as $action) {
            $container = Container::getInstance();
            $container->bind(\Atx\ResourceIndex\Contracts\ResourceIndex::class, ResourceIndex::class);
            $router = new Router(new Dispatcher($container), $container);
            $router->get('/users', $action);
            $request = Request::create('/users');
            $container->instance('request', $request);

            $response = $router->dispatch($request);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame([['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']], $response->getData(true)['data']);
        }
    }
}
