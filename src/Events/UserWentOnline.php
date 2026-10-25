<?php

namespace EvanSchleret\LaravelUserPresence\Events;

class UserWentOnline extends UserPresenceEvent
{
    public function broadcastAs(): string
    {
        return 'user.went.online';
    }

    protected function status(): string
    {
        return 'online';
    }
}
