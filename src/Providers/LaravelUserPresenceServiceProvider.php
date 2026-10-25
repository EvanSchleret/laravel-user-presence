<?php

namespace EvanSchleret\LaravelUserPresence\Providers;

use EvanSchleret\LaravelUserPresence\Console\Commands\UpdatePresenceStatuses;
use EvanSchleret\LaravelUserPresence\Contracts\PresenceChannelAuthorizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class LaravelUserPresenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfig();
    }

    public function boot(): void
    {
        $channelAuthorizer = config('user-presence.channel_authorizer');

        Broadcast::channel(
            'user-presence.{userId}',
            function (Authenticatable $user, string $userId) use ($channelAuthorizer): bool {
                if ($channelAuthorizer === null) {
                    return (string) $user->getAuthIdentifier() === $userId;
                }

                if (! is_string($channelAuthorizer) || ! class_exists($channelAuthorizer)) {
                    throw new \InvalidArgumentException('The configured presence channel authorizer must be a class name.');
                }

                $authorizer = $this->app->make($channelAuthorizer);

                if (! $authorizer instanceof PresenceChannelAuthorizer) {
                    throw new \InvalidArgumentException('The configured presence channel authorizer must implement PresenceChannelAuthorizer.');
                }

                return $authorizer->authorize($user, $userId);
            },
            config('user-presence.guard') ? ['guards' => [config('user-presence.guard')]] : [],
        );

        $this->publishes([
            __DIR__.'/../config/user-presence.php' => config_path('user-presence.php'),
        ]);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([UpdatePresenceStatuses::class]);
        }
    }

    private function mergeConfig(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/user-presence.php', 'user-presence'
        );
    }
}
