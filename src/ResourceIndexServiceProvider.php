<?php

declare(strict_types=1);

namespace Atx\ResourceIndex;

use Atx\ResourceIndex\Contracts\ResourceIndex as ResourceIndexContract;
use Illuminate\Support\ServiceProvider;

class ResourceIndexServiceProvider extends ServiceProvider
{
    public array $bindings = [
        ResourceIndexContract::class => ResourceIndex::class,
    ];
}
