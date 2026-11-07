<?php

namespace EvanSchleret\LaravelUserPresence\Tests\Fixtures;

use EvanSchleret\LaravelUserPresence\Contracts\PresencePolicy;
use Illuminate\Database\Eloquent\Model;

class ConfigurablePresencePolicy implements PresencePolicy
{
    public function isEnabled(Model $user): bool
    {
        return (bool) $user->getAttribute('presence_enabled');
    }
}
