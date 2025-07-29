<?php

namespace EvanSchleret\LaravelUserPresence\Traits;

use Illuminate\Support\Carbon;
use EvanSchleret\LaravelUserPresence\Events\UserWentOnline;
use EvanSchleret\LaravelUserPresence\Events\UserWentIdle;
use EvanSchleret\LaravelUserPresence\Events\UserWentOffline;

trait HasUserPresence
{
    public function updatePresenceStatus(): void
    {
        $now = now();
        $previous = $this->last_seen_at;

        $this->forceFill(['last_seen_at' => $now])->saveQuietly();

        if (!$previous) {
            UserWentOnline::dispatch($this);
            return;
        }

        $onlineThreshold = (int) config('user-presence.online_threshold');
        $idleThreshold = (int) config('user-presence.idle_threshold');

        $wasOnline = $previous->gt($now->clone()->subSeconds($onlineThreshold));
        $wasIdle = $previous->gt($now->clone()->subSeconds($idleThreshold));

        if (!$wasOnline) {
            UserWentOnline::dispatch($this);
        } elseif (!$wasIdle) {
            UserWentIdle::dispatch($this);
        }
    }

    public function getIsOnlineAttribute(): bool
    {
        return $this->last_seen_at?->gt(now()->subSeconds(config('user-presence.online_threshold'))) ?? false;
    }

    public function getIsOfflineAttribute(): bool
    {
        return ! $this->is_online;
    }

    public function getIsIdleAttribute(): bool
    {
        $last = $this->last_seen_at;

        return $last?->lte(now()->subSeconds(config('user-presence.online_threshold')))
        && $last->gt(now()->subSeconds(config('user-presence.idle_threshold'))) ?? false;
    }

    public function getLastSeenAgoAttribute(): string
    {
        return $this->last_seen_at?->diffForHumans() ?? 'never';
    }

    public function getPresenceStatusAttribute(): string
    {
        return match (true) {
            $this->is_online => 'online',
            $this->is_idle => 'idle',
            default => 'offline'
        };
    }
}
