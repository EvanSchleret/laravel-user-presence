<?php

namespace EvanSchleret\LaravelUserPresence\Contracts;

use Illuminate\Database\Eloquent\Model;

interface PresencePolicy
{
    public function isEnabled(Model $user): bool;
}
