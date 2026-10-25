<?php

namespace EvanSchleret\LaravelUserPresence\Events;

class UserWentIdle extends UserPresenceEvent
{
    public function broadcastAs(): string
    {
        return 'user.went.idle';
    }

    protected function status(): string
    {
        return 'idle';
    }
}
