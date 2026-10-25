<?php

namespace EvanSchleret\LaravelUserPresence\Events;

class UserWentOffline extends UserPresenceEvent
{
    public function broadcastAs(): string
    {
        return 'user.went.offline';
    }

    protected function status(): string
    {
        return 'offline';
    }
}
