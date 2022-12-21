<?php

declare(strict_types=1);

namespace Atx\ResourceIndex;

use Illuminate\Support\ServiceProvider;

class ResourceIndexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \Atx\ResourceIndex\Contracts\ResourceIndex::class,
            \Atx\ResourceIndex\Contracts\ResourceIndex::class
        );
    }
}
