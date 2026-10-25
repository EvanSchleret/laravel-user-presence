<?php

namespace EvanSchleret\LaravelUserPresence\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class UserPresenceEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Authenticatable $user) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user-presence.'.$this->user->getAuthIdentifier())];
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->getAuthIdentifier(),
            'status' => $this->status(),
        ];
    }

    abstract protected function status(): string;
}
