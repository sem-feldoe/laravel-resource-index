<?php

declare(strict_types=1);

namespace Atx\ResourceIndex;

use Atx\ResourceIndex\Contracts\ResourceIndex as ResourceIndexContract;
use Illuminate\Support\ServiceProvider;
use Laravel\Ranger\Ranger;
use Laravel\Surveyor\Analyzer\ResourceAnalyzer;
use Laravel\Wayfinder\WayfinderServiceProvider;

class ResourceIndexServiceProvider extends ServiceProvider
{
    public array $bindings = [
        ResourceIndexContract::class => ResourceIndex::class,
    ];

    public function boot(): void
    {
        if (class_exists(Ranger::class)
            && class_exists(ResourceAnalyzer::class)
            && class_exists(WayfinderServiceProvider::class)) {
            $this->app->register(Wayfinder\WayfinderServiceProvider::class);
        }
    }
}
