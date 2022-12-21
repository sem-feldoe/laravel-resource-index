<?php

declare(strict_types=1);

namespace Atx\ResourceIndex\Exceptions;

use Exception;

class NotAModelClassException extends Exception
{
    public static function of(object|string $class): self
    {
        if (! is_string($class)) {
            $class = get_class($class);
        }

        return new self(__("`$class` is not a model."));
    }
}
