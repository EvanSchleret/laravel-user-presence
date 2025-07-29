<?php

namespace EvanSchleret\LaravelUserPresence\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class LaravelUserPresenceServiceProvider extends ServiceProvider
{
    /**
     * Register LaraMjml service.
     */
    public function register(): void
    {
        $this->mergeConfig();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/laravel-user-presence.php' => config_path('laravel-user-presence.php'),
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ]);
    }

    /**
     * Merge the configuration.
     */
    private function mergeConfig(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laravel-user-presence.php', 'laravel-user-presence'
        );
    }

    /**
     * Publish the configuration.
     */
    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/laravel-user-presence.php' => config_path('laravel-user-presence.php'),
        ]);
    }
}
