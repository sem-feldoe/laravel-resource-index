<?php

declare(strict_types=1);

namespace Atx\ResourceIndex\Tests\Fixtures;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => (int) $this->id, 'name' => (string) $this->name];
    }
}
