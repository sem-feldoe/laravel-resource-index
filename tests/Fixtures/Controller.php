<?php

declare(strict_types=1);

namespace Atx\ResourceIndex\Tests\Fixtures;

use Atx\ResourceIndex\Attributes\ResourceIndexResponse;
use Atx\ResourceIndex\Contracts\ResourceIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class Controller
{
    #[ResourceIndexResponse(UserResource::class)]
    public function index(Request $request, ResourceIndex $index): JsonResponse
    {
        return $index->from(User::class)->processRequest($request)->response();
    }

    public function legacy(): void {}
}
