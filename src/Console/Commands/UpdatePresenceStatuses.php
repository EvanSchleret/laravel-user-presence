<?php

namespace EvanSchleret\LaravelUserPresence\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class UpdatePresenceStatuses extends Command
{
    protected $signature = 'user-presence:update-statuses';

    protected $description = 'Broadcast user presence status transitions';

    public function handle(): int
    {
        $store = config('user-presence.store', 'auto');

        if ($store === 'cache') {
            $this->info('Cached presence transitions are evaluated when status is read or activity is recorded.');

            return self::SUCCESS;
        }

        $model = $this->presenceModel();
        $instance = new $model;
        $table = $instance->getTable();
        $schema = $instance->getConnection()->getSchemaBuilder();

        if ($store === 'auto' && ! $schema->hasColumn($table, 'last_seen_at')) {
            $this->info('Cached presence transitions are evaluated when status is read or activity is recorded.');

            return self::SUCCESS;
        }

        if (! $schema->hasColumn($table, 'last_seen_at') || ! $schema->hasColumn($table, 'presence_broadcast_status')) {
            $this->info('The configured model does not use the legacy presence columns.');

            return self::SUCCESS;
        }

        $onlineCutoff = now()->subSeconds((int) config('user-presence.online_threshold', 300));
        $idleCutoff = now()->subSeconds((int) config('user-presence.idle_threshold', 600));

        $model::query()
            ->whereIn('presence_broadcast_status', ['online', 'idle'])
            ->where(function ($query) use ($onlineCutoff, $idleCutoff): void {
                $query->where(function ($query) use ($onlineCutoff): void {
                    $query->where('presence_broadcast_status', 'online')
                        ->where(function ($query) use ($onlineCutoff): void {
                            $query->whereNull('last_seen_at')
                                ->orWhere('last_seen_at', '<=', $onlineCutoff);
                        });
                })->orWhere(function ($query) use ($idleCutoff): void {
                    $query->where('presence_broadcast_status', 'idle')
                        ->where(function ($query) use ($idleCutoff): void {
                            $query->whereNull('last_seen_at')
                                ->orWhere('last_seen_at', '<=', $idleCutoff);
                        });
                });
            })
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $user->syncPresenceBroadcastStatus();
                }
            });

        $this->info('Presence statuses synchronized.');

        return self::SUCCESS;
    }

    private function presenceModel(): string
    {
        $model = config('user-presence.model');

        if (! is_string($model) || $model === '') {
            $guard = config('user-presence.guard') ?? config('auth.defaults.guard');
            $provider = $guard ? config("auth.guards.{$guard}.provider") : null;
            $model = $provider ? config("auth.providers.{$provider}.model") : null;
        }

        if (! is_string($model)
            || ! class_exists($model)
            || ! is_subclass_of($model, Model::class)
            || ! method_exists($model, 'syncPresenceBroadcastStatus')) {
            throw new RuntimeException('Configure user-presence.model with an Eloquent model that uses HasUserPresence.');
        }

        return $model;
    }
}
