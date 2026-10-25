<?php

namespace EvanSchleret\LaravelUserPresence\Tests\Fixtures;

use EvanSchleret\LaravelUserPresence\Traits\HasUserPresence;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasUserPresence;

    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];
}
