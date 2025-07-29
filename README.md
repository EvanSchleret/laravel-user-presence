<div align="center">

<img src="logo.png" alt="Laravel User Presence logo" width="250">

# Laravel User Presence

[![Latest Version on Packagist](https://img.shields.io/packagist/v/evanschleret/laravel-user-presence.svg?style=flat-square)](https://packagist.org/packages/evanschleret/laravel-user-presence)
[![Total Downloads](https://img.shields.io/packagist/dt/evanschleret/laravel-user-presence.svg?style=flat-square)](https://packagist.org/packages/evanschleret/laravel-user-presence)
</div>

This package allows you to track user presence in your Laravel application. It provides a simple way to determine if a user is online, offline, or idle. Useful for chat apps, dashboards, real-time features, and more.

## Installation

```bash
composer require evanschleret/laravel-user-presence
```

Optionally, publish the config file:

```bash
php artisan vendor:publish --provider="EvanSchleret\LaravelUserPresence\Providers\LaravelUserPresenceServiceProvider"
```

# Requirements

- Laravel 10 or 11
- PHP >= 8.2
- A last_seen_at column on your users table (or whichever model you attach presence to)

```
$table->timestamp('last_seen_at')->nullable();
```

You could also publish the migration file to create this column:

```bash
php artisan vendor:publish --provider="EvanSchleret\LaravelUserPresence\Providers\LaravelUserPresenceServiceProvider" --tag="migrations"
```

Then run the migration:

```bash
php artisan migrate
```

# Usage

Trait
Add the trait to your User model:

```php
use EvanSchleret\LaravelUserPresence\Traits\HasUserPresence;

class User extends Authenticatable
{
    use HasUserPresence;
}
```

## Middleware
Add the UpdateLastSeen middleware to your web or api group to update presence on each request:

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'web' => [
        // ...
        \EvanSchleret\LaravelUserPresence\Http\Middleware\UpdateLastSeen::class,
    ],
];

```

## Attributes available
After setup, the following attributes will be available on the user model:

```
$user->is_online       // true if active in the last 2 minutes
$user->is_offline      // inverse of is_online
$user->is_idle         // true if inactive for 2–10 minutes
$user->last_seen_ago   // "3 minutes ago", "never", etc.
$user->presence_status // 'online', 'away', or 'offline'
```

## Configuration

You can customize time thresholds in the published config file:

```php
// config/user-presence.php
return [
    'online_threshold' => 300,  // in seconds
    'idle_threshold' => 600,     // in seconds
    'guard' => 'web', // or 'api' / 'sanctum' if using API authentication
];
```

## Events

The package dispatches these events when presence changes:

```
UserWentOnline
UserWentOffline
UserWentIdle
```

# Roadmap

- [ ] Tests
- [ ] Redis driver for real-time broadcast presence
- [ ] Eloquent observer-based updates

# Contributing
I'm always open to contributions! Feel free to submit issues or pull requests on GitHub.

# License
This package is open-sourced software licensed under the [MIT license](LICENSE.md).
