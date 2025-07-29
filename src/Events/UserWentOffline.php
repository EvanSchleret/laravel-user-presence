<?php

namespace EvanSchleret\LaravelUserPresence\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Contracts\Auth\Authenticatable;

class UserWentOffline
{
    use Dispatchable, SerializesModels;

    public function __construct(public Authenticatable $user) {}
}
