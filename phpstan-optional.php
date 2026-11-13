<?php

declare(strict_types=1);

use Laravel\Ranger\Ranger;

return [
    'parameters' => [
        'excludePaths' => [
            'analyse' => class_exists(Ranger::class) ? [] : [__DIR__.'/src/Wayfinder'],
        ],
    ],
];
