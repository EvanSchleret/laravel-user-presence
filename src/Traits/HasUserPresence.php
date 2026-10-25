<?php

namespace EvanSchleret\LaravelUserPresence\Traits;

use EvanSchleret\LaravelUserPresence\Events\UserWentIdle;
use EvanSchleret\LaravelUserPresence\Events\UserWentOffline;
use EvanSchleret\LaravelUserPresence\Events\UserWentOnline;
use Illuminate\Support\Carbon;

trait HasUserPresence
{
    public function updatePresenceStatus(): void
    {
        while (true) {
            $now = now();
            $lastSeenAt = $this->lastSeenAt();
            $previousStatus = $this->getAttribute('presence_broadcast_status');

            if (! is_string($previousStatus)) {
                $previousStatus = $lastSeenAt === null ? null : $this->statusFor($lastSeenAt, $now);
            }

            if (! $this->savePresenceAttributes([
                'last_seen_at' => $now,
                'presence_broadcast_status' => 'online',
            ])) {
                continue;
            }

            $this->dispatchPresenceTransition($previousStatus, 'online');

            return;
        }
    }

    public function syncPresenceBroadcastStatus(): bool
    {
        while (true) {
            $now = now();
            $currentStatus = $this->statusFor($this->lastSeenAt(), $now);
            $previousStatus = $this->getAttribute('presence_broadcast_status');

            if (! is_string($previousStatus)) {
                if (! $this->savePresenceAttributes(['presence_broadcast_status' => $currentStatus])) {
                    continue;
                }

                return false;
            }

            if ($previousStatus === $currentStatus) {
                return false;
            }

            if (! $this->savePresenceAttributes(['presence_broadcast_status' => $currentStatus])) {
                continue;
            }

            $this->dispatchPresenceTransition($previousStatus, $currentStatus);

            return true;
        }
    }

    public function getIsOnlineAttribute(): bool
    {
        return $this->lastSeenAt()?->gt(now()->subSeconds((int) config('user-presence.online_threshold'))) ?? false;
    }

    public function getIsOfflineAttribute(): bool
    {
        return ! $this->is_online;
    }

    public function getIsIdleAttribute(): bool
    {
        $last = $this->lastSeenAt();

        if ($last === null) {
            return false;
        }

        return $last->lte(now()->subSeconds((int) config('user-presence.online_threshold')))
            && $last->gt(now()->subSeconds((int) config('user-presence.idle_threshold')));
    }

    public function getLastSeenAgoAttribute(): string
    {
        return $this->lastSeenAt()?->diffForHumans() ?? 'never';
    }

    public function getPresenceStatusAttribute(): string
    {
        return match (true) {
            $this->is_online => 'online',
            $this->is_idle => 'idle',
            default => 'offline'
        };
    }

    private function lastSeenAt(): ?Carbon
    {
        $lastSeenAt = $this->getAttribute('last_seen_at');

        return $lastSeenAt === null ? null : Carbon::parse($lastSeenAt);
    }

    private function statusFor(?Carbon $lastSeenAt, Carbon $now): string
    {
        if ($lastSeenAt === null) {
            return 'offline';
        }

        if ($lastSeenAt->gt($now->copy()->subSeconds((int) config('user-presence.online_threshold')))) {
            return 'online';
        }

        if ($lastSeenAt->gt($now->copy()->subSeconds((int) config('user-presence.idle_threshold')))) {
            return 'idle';
        }

        return 'offline';
    }

    private function savePresenceAttributes(array $attributes): bool
    {
        $original = $this->getRawOriginal();
        $unchanged = true;

        foreach ($attributes as $attribute => $value) {
            $expected = $attribute === 'last_seen_at' ? $this->fromDateTime($value) : $value;

            if (($original[$attribute] ?? null) !== $expected) {
                $unchanged = false;
                break;
            }
        }

        if ($unchanged) {
            return true;
        }

        $query = $this->newQuery()->whereKey($this->getKey());

        foreach (['last_seen_at', 'presence_broadcast_status'] as $attribute) {
            $value = $original[$attribute] ?? null;
            $value === null
                ? $query->whereNull($attribute)
                : $query->where($attribute, $value);
        }

        if ($query->update($attributes) === 0) {
            $this->refresh();

            return false;
        }

        $this->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));

        return true;
    }

    private function dispatchPresenceTransition(?string $previousStatus, string $currentStatus): void
    {
        if ($previousStatus === $currentStatus) {
            return;
        }

        if ($currentStatus === 'online') {
            if ($previousStatus === 'idle') {
                UserWentIdle::dispatch($this);
            }

            if ($previousStatus === 'offline') {
                UserWentOffline::dispatch($this);
            }

            UserWentOnline::dispatch($this);

            return;
        }

        if ($currentStatus === 'idle') {
            UserWentIdle::dispatch($this);
        }

        if ($currentStatus === 'offline') {
            UserWentOffline::dispatch($this);
        }
    }
}
