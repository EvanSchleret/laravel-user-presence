<?php

namespace EvanSchleret\LaravelUserPresence\Services;

use EvanSchleret\LaravelUserPresence\Events\UserWentIdle;
use EvanSchleret\LaravelUserPresence\Events\UserWentOffline;
use EvanSchleret\LaravelUserPresence\Events\UserWentOnline;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class PresenceService
{
    private array $resolvedStores = [];

    public function recordRequestActivity(Model $user, Request $request): void
    {
        $activityId = null;

        if (method_exists($user, 'currentAccessToken')) {
            $accessToken = $user->currentAccessToken();

            if (is_object($accessToken) && method_exists($accessToken, 'getKey') && $accessToken->getKey() !== null) {
                $activityId = 'token:'.$accessToken->getKey();
            }
        }

        if ($activityId === null && $request->hasSession()) {
            $sessionId = $request->session()->getId();

            if ($sessionId !== '') {
                $activityId = 'session:'.$sessionId;
            }
        }

        if ($activityId === null) {
            $deviceId = $request->header('X-Presence-Device');
            $activityId = is_string($deviceId) && $deviceId !== ''
                ? 'device:'.substr($deviceId, 0, 256)
                : 'default';
        }

        $this->recordActivity($user, $activityId);
    }

    public function recordActivity(Model $user, string $activityId = 'default'): void
    {
        $identity = $this->identity($user);

        if ($identity === null) {
            return;
        }

        [$repository, $key, $lockKey] = $identity;

        $this->withLock($repository, $lockKey, function () use ($repository, $key, $user, $activityId): void {
            $now = now();
            $state = $this->readState($repository, $key);
            $activities = $this->validActivities($state['activities'] ?? [], $now);
            $lastSeenAt = $this->usesColumnStore($user)
                ? $this->columnLastSeenAt($user)
                : $this->latestActivity($activities);
            $storedStatus = $state['evaluated_status'] ?? $user->getAttribute('presence_broadcast_status');
            $hasStoredStatus = is_string($storedStatus) && in_array($storedStatus, ['online', 'idle', 'offline'], true);
            $previousStatus = $hasStoredStatus ? $storedStatus : null;

            if ($lastSeenAt === null) {
                $previousStatus = null;
                $hasStoredStatus = false;
            } elseif (! $hasStoredStatus) {
                $previousStatus = $this->statusFor($lastSeenAt, $now);
            }

            $observedStatus = $lastSeenAt === null ? null : $this->statusFor($lastSeenAt, $now);
            $dirty = $activities !== ($state['activities'] ?? []);

            if ($observedStatus !== null && $hasStoredStatus && $previousStatus !== $observedStatus) {
                $this->dispatchTransition($user, $previousStatus, $observedStatus);
                $previousStatus = $observedStatus;
                $dirty = true;
            }

            $actorKey = hash('sha256', $activityId);
            $actorLastSeenAt = $this->parseTimestamp($activities[$actorKey] ?? null);
            $writeInterval = $this->activityWriteInterval();
            $shouldWrite = $actorLastSeenAt === null
                || $writeInterval === 0
                || $actorLastSeenAt->lte($now->copy()->subSeconds($writeInterval));

            if ($shouldWrite) {
                if ($this->usesColumnStore($user)) {
                    $user->newQuery()->whereKey($user->getKey())->update(['last_seen_at' => $now]);
                    $user->setAttribute('last_seen_at', $now);
                    $user->syncOriginalAttribute('last_seen_at');
                }

                $activities[$actorKey] = $now->toIso8601String();
                if ($previousStatus !== 'online') {
                    if (! $hasStoredStatus && $observedStatus !== null && $observedStatus !== 'online') {
                        $this->dispatchTransition($user, $observedStatus, 'online');
                    } else {
                        $this->dispatchOnline($user);
                    }
                }
                $state['evaluated_status'] = 'online';
                $this->writeLegacyBroadcastStatus($user, 'online');
                $dirty = true;
            } elseif ($observedStatus !== null && ($state['evaluated_status'] ?? null) !== $observedStatus) {
                $state['evaluated_status'] = $observedStatus;
                $dirty = true;
            }

            if ($activities !== ($state['activities'] ?? [])) {
                $state['activities'] = $activities;
                $dirty = true;
            }

            if ($dirty) {
                $this->writeState($repository, $key, $state, $now);
            }
        });
    }

    public function status(Model $user): string
    {
        $identity = $this->identity($user);

        if ($identity === null) {
            return 'offline';
        }

        [$repository, $key, $lockKey] = $identity;

        return $this->withLock($repository, $lockKey, function () use ($repository, $key, $user): string {
            $now = now();
            $state = $this->readState($repository, $key);
            $activities = $this->validActivities($state['activities'] ?? [], $now);
            $lastSeenAt = $this->usesColumnStore($user)
                ? $this->columnLastSeenAt($user)
                : $this->latestActivity($activities);

            if ($lastSeenAt === null) {
                if ($this->usesCacheStore($user) && $state !== []) {
                    $repository->forget($key);
                } elseif (($state['evaluated_status'] ?? null) !== null) {
                    $state['evaluated_status'] = null;
                    $this->writeState($repository, $key, $state, $now);
                }

                return 'offline';
            }

            $currentStatus = $this->statusFor($lastSeenAt, $now);
            $previousStatus = $state['evaluated_status'] ?? $user->getAttribute('presence_broadcast_status');

            if (is_string($previousStatus) && $previousStatus !== $currentStatus) {
                $this->dispatchTransition($user, $previousStatus, $currentStatus);
            }

            if (($state['activities'] ?? []) !== $activities || ($state['evaluated_status'] ?? null) !== $currentStatus) {
                $state['activities'] = $activities;
                $state['evaluated_status'] = $currentStatus;
                $this->writeState($repository, $key, $state, $now);
            }

            $this->writeLegacyBroadcastStatus($user, $currentStatus);

            return $currentStatus;
        });
    }

    public function lastSeenAt(Model $user): ?Carbon
    {
        if ($this->usesColumnStore($user)) {
            return $this->columnLastSeenAt($user);
        }

        $identity = $this->identity($user);

        if ($identity === null) {
            return null;
        }

        [$repository, $key] = $identity;
        $activities = $this->validActivities($this->readState($repository, $key)['activities'] ?? [], now());

        return $this->latestActivity($activities);
    }

    public function syncStatus(Model $user): bool
    {
        if ($this->lastSeenAt($user) === null) {
            return false;
        }

        $identity = $this->identity($user);

        if ($identity === null) {
            return false;
        }

        [$repository, $key] = $identity;
        $previousStatus = $this->readState($repository, $key)['evaluated_status']
            ?? $user->getAttribute('presence_broadcast_status');
        $currentStatus = $this->status($user);

        return is_string($previousStatus) && $previousStatus !== $currentStatus;
    }

    private function identity(Model $user): ?array
    {
        $id = $user->getKey();

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $repository = Cache::store($this->cacheStore());
        $prefix = trim((string) config('user-presence.cache_prefix', 'laravel-user-presence'), ':');
        $key = $prefix
            .':'.hash('sha256', get_class($user))
            .':'.hash('sha256', (string) $id);

        return [$repository, $key, $key.':lock'];
    }

    private function withLock(object $repository, string $lockKey, callable $callback): mixed
    {
        if (! $repository->getStore() instanceof LockProvider) {
            return $callback();
        }

        return $repository->lock($lockKey, 10)->block(5, $callback);
    }

    private function readState(object $repository, string $key): array
    {
        $state = $repository->get($key, []);

        return is_array($state) ? $state : [];
    }

    private function writeState(object $repository, string $key, array $state, Carbon $now): void
    {
        $state['activities'] = $state['activities'] ?? [];
        $repository->put($key, $state, $now->copy()->addSeconds($this->cacheRetention()));
    }

    private function validActivities(mixed $activities, Carbon $now): array
    {
        if (! is_array($activities)) {
            return [];
        }

        $cutoff = $now->copy()->subSeconds($this->cacheRetention());
        $valid = [];

        foreach ($activities as $actor => $timestamp) {
            $seenAt = $this->parseTimestamp($timestamp);

            if ($seenAt !== null && $seenAt->gt($cutoff)) {
                $valid[(string) $actor] = $seenAt->toIso8601String();
            }
        }

        return $valid;
    }

    private function latestActivity(array $activities): ?Carbon
    {
        $latest = null;

        foreach ($activities as $timestamp) {
            $seenAt = $this->parseTimestamp($timestamp);

            if ($seenAt !== null && ($latest === null || $seenAt->gt($latest))) {
                $latest = $seenAt;
            }
        }

        return $latest;
    }

    private function parseTimestamp(mixed $timestamp): ?Carbon
    {
        if (! is_string($timestamp) && ! is_int($timestamp) && ! is_float($timestamp)) {
            return null;
        }

        try {
            return Carbon::parse((string) $timestamp);
        } catch (Throwable) {
            return null;
        }
    }

    private function columnLastSeenAt(Model $user): ?Carbon
    {
        $lastSeenAt = $user->getAttribute('last_seen_at');

        return $lastSeenAt === null ? null : Carbon::parse($lastSeenAt);
    }

    private function writeLegacyBroadcastStatus(Model $user, string $status): void
    {
        if (! array_key_exists('presence_broadcast_status', $user->getAttributes())) {
            return;
        }

        if ($user->getRawOriginal('presence_broadcast_status') !== $status) {
            $user->newQuery()
                ->whereKey($user->getKey())
                ->where(function ($query) use ($status): void {
                    $query->whereNull('presence_broadcast_status')
                        ->orWhere('presence_broadcast_status', '!=', $status);
                })
                ->update(['presence_broadcast_status' => $status]);
        }

        $user->setAttribute('presence_broadcast_status', $status);
        $user->syncOriginalAttribute('presence_broadcast_status');
    }

    private function statusFor(Carbon $lastSeenAt, Carbon $now): string
    {
        if ($lastSeenAt->gt($now->copy()->subSeconds($this->onlineThreshold()))) {
            return 'online';
        }

        if ($lastSeenAt->gt($now->copy()->subSeconds($this->idleThreshold()))) {
            return 'idle';
        }

        return 'offline';
    }

    private function dispatchTransition(Model $user, ?string $previousStatus, string $currentStatus): void
    {
        if (! config('user-presence.broadcasting.enabled', true) || $previousStatus === $currentStatus) {
            return;
        }

        if ($currentStatus === 'online') {
            if ($previousStatus === 'idle') {
                UserWentIdle::dispatch($user);
            }

            if ($previousStatus === 'offline') {
                UserWentOffline::dispatch($user);
            }

            $this->dispatchOnline($user);

            return;
        }

        if ($currentStatus === 'idle') {
            UserWentIdle::dispatch($user);
        }

        if ($currentStatus === 'offline') {
            UserWentOffline::dispatch($user);
        }
    }

    private function dispatchOnline(Model $user): void
    {
        if (config('user-presence.broadcasting.enabled', true)) {
            UserWentOnline::dispatch($user);
        }
    }

    private function storageDriver(Model $user): string
    {
        $driver = config('user-presence.store', 'auto');

        if ($driver === 'auto') {
            $connection = $user->getConnection()->getName();
            $table = $user->getTable();
            $cacheKey = $connection.':'.$table;

            if (! array_key_exists($cacheKey, $this->resolvedStores)) {
                $this->resolvedStores[$cacheKey] = $user->getConnection()
                    ->getSchemaBuilder()
                    ->hasColumn($table, 'last_seen_at')
                    ? 'column'
                    : 'cache';
            }

            return $this->resolvedStores[$cacheKey];
        }

        if (! is_string($driver) || ! in_array($driver, ['cache', 'column'], true)) {
            throw new InvalidArgumentException('The user-presence store must be auto, cache, or column.');
        }

        return $driver;
    }

    private function usesCacheStore(Model $user): bool
    {
        return $this->storageDriver($user) === 'cache';
    }

    private function usesColumnStore(Model $user): bool
    {
        return $this->storageDriver($user) === 'column';
    }

    private function cacheStore(): ?string
    {
        $store = config('user-presence.cache_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    private function onlineThreshold(): int
    {
        return max(0, (int) config('user-presence.online_threshold', 300));
    }

    private function idleThreshold(): int
    {
        return max($this->onlineThreshold(), (int) config('user-presence.idle_threshold', 600));
    }

    private function cacheRetention(): int
    {
        return max(
            1,
            (int) config('user-presence.cache_retention', 86400),
            $this->idleThreshold() + 1,
        );
    }

    private function activityWriteInterval(): int
    {
        $configuredInterval = max(0, (int) config('user-presence.activity_write_interval', 60));

        if ($this->onlineThreshold() === 0) {
            return 0;
        }

        return min($configuredInterval, $this->onlineThreshold() - 1);
    }
}
