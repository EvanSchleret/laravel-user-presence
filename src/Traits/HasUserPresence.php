<?php

namespace EvanSchleret\LaravelUserPresence\Traits;

use EvanSchleret\LaravelUserPresence\Services\PresenceService;

trait HasUserPresence
{
    public function updatePresenceStatus(?string $activityId = null): void
    {
        app(PresenceService::class)->recordActivity($this, $activityId ?? 'default');
    }

    public function syncPresenceBroadcastStatus(): bool
    {
        return app(PresenceService::class)->syncStatus($this);
    }

    public function getIsOnlineAttribute(): bool
    {
        return app(PresenceService::class)->status($this) === 'online';
    }

    public function getIsOfflineAttribute(): bool
    {
        return ! $this->is_online;
    }

    public function getIsIdleAttribute(): bool
    {
        return app(PresenceService::class)->status($this) === 'idle';
    }

    public function getLastSeenAgoAttribute(): string
    {
        return app(PresenceService::class)->lastSeenAt($this)?->diffForHumans() ?? 'never';
    }

    public function getPresenceStatusAttribute(): string
    {
        return app(PresenceService::class)->status($this);
    }
}
