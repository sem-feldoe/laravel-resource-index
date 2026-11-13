<?php

declare(strict_types=1);

namespace Atx\ResourceIndex\Wayfinder;

use Illuminate\Support\ServiceProvider;
use Laravel\Ranger\Ranger;

final class WayfinderServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->resolving(Ranger::class, function (Ranger $ranger): void {
            $ranger->onRoute($this->app->make(DescribeResponse::class));
        });
    }
}
