<?php

declare(strict_types=1);

namespace Atx\ResourceIndex\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
    protected $table = 'fixture_users';

    protected $guarded = [];

    public $timestamps = false;
}
