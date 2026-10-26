<?php

namespace EvanSchleret\LaravelUserPresence\Providers;

use EvanSchleret\LaravelUserPresence\Console\Commands\UpdatePresenceStatuses;
use EvanSchleret\LaravelUserPresence\Contracts\PresenceChannelAuthorizer;
use EvanSchleret\LaravelUserPresence\Http\Controllers\HeartbeatController;
use EvanSchleret\LaravelUserPresence\Services\PresenceService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LaravelUserPresenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PresenceService::class);
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

        $this->registerHeartbeatRoute();

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

    private function registerHeartbeatRoute(): void
    {
        if (! config('user-presence.heartbeat.enabled', true)) {
            return;
        }

        $guard = config('user-presence.guard');
        $rateLimit = config('user-presence.heartbeat.rate_limit', []);
        $attempts = max(1, (int) ($rateLimit['attempts'] ?? 60));
        $decayMinutes = max(1, (int) ($rateLimit['decay_minutes'] ?? 1));
        $middleware = array_values((array) config('user-presence.heartbeat.middleware', ['web']));
        $middleware[] = $guard ? 'auth:'.$guard : 'auth';
        $middleware[] = "throttle:{$attempts},{$decayMinutes}";

        Route::middleware($middleware)
            ->post(trim((string) config('user-presence.heartbeat.path', 'user-presence/heartbeat'), '/'), HeartbeatController::class)
            ->name('user-presence.heartbeat');
    }
}
