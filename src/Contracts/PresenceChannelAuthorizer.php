<?php

namespace EvanSchleret\LaravelUserPresence\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface PresenceChannelAuthorizer
{
    public function authorize(Authenticatable $user, string $presenceUserId): bool;
}
