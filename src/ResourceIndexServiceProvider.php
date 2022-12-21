<?php

declare(strict_types=1);

namespace Atx\ResourceIndex;

use Illuminate\Support\ServiceProvider;
use Atx\ResourceIndex\Contracts\ResourceIndex as ResourceIndexContract;

class ResourceIndexServiceProvider extends ServiceProvider
{
    public array $bindings = [
        ResourceIndexContract::class => ResourceIndex::class,
    ];
}
