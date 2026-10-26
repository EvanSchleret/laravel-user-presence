<div align="center">

<img src="logo.png" alt="Laravel User Presence — Online, Idle, Offline" width="900">

[![Latest Version on Packagist](https://img.shields.io/packagist/v/evanschleret/laravel-user-presence.svg?style=flat-square)](https://packagist.org/packages/evanschleret/laravel-user-presence)
</div>

Track whether Laravel users are online, idle, or offline. The package stores server-side activity in Laravel's cache by default and can broadcast calculated status transitions.

## Requirements

- Laravel 12 or 13
- PHP 8.2 or later (Laravel 13 requires PHP 8.3 or later)
- A Laravel cache store; Redis or Memcached is recommended for production

## Installation

Install the package with Composer:

```bash
composer require evanschleret/laravel-user-presence
```

Add the trait to your authenticatable Eloquent model:

```php
use EvanSchleret\LaravelUserPresence\Traits\HasUserPresence;

class User extends Authenticatable
{
    use HasUserPresence;
}
```

The default cache store needs no database migration or user-table columns. Publish the configuration only when you need to change the defaults:

```bash
php artisan vendor:publish --provider="EvanSchleret\LaravelUserPresence\Providers\LaravelUserPresenceServiceProvider"
```

## Status values

The package calculates each status from the latest known activity and the current server time. The default thresholds mark a user online for five minutes, idle from five to ten minutes, and offline after ten minutes.

| Status | Elapsed time |
| --- | --- |
| `online` | Less than `online_threshold` (300 seconds) |
| `idle` | At least `online_threshold` and less than `idle_threshold` (600 seconds) |
| `offline` | At least `idle_threshold` |

A user without a known activity is offline. The `last_seen_ago` attribute returns `never` when no timestamp is available. With cache storage, `never` also means the timestamp expired from cache; the package does not keep a permanent activity history.

The model exposes `$user->is_online`, `$user->is_idle`, `$user->is_offline`, `$user->last_seen_ago`, and `$user->presence_status`. For v2.0 compatibility, `is_offline` remains the inverse of `is_online`, so it is also true while a user is idle; use `$user->presence_status === 'offline'` to check the offline state exactly.

## Cache storage

The `auto` storage mode is the default. It keeps using `last_seen_at` when the model's table already has that column, which preserves existing installations, and uses cache when the column is absent. Laravel's default cache store is used unless you select a named store. Cache keys include the model class and model identifier, and activity timestamps are separated by session, access token, or configured device identifier.

In `config/user-presence.php`, set the storage and threshold options:

```php
return [
    'store' => 'auto',
    'cache_store' => env('LARAVEL_USER_PRESENCE_CACHE_STORE'),
    'cache_prefix' => 'laravel-user-presence',
    'cache_retention' => 86400,
    'activity_write_interval' => 60,
    'online_threshold' => 300,
    'idle_threshold' => 600,
];
```

Set `store` to `cache` to use cache even when a `last_seen_at` column exists. Set `LARAVEL_USER_PRESENCE_CACHE_STORE` to a configured Laravel store such as `redis` or `memcached`. Redis and Memcached are recommended for production, but Laravel's other cache stores remain supported. Use a shared cache store when the application runs on multiple servers.

The effective cache retention is the greater of `cache_retention` and `idle_threshold + 1`. This keeps timestamps available beyond the offline boundary. If a key eventually expires, the next read reports offline because no activity is known; key expiration does not emit an offline transition.

## Heartbeat endpoint

The package registers an authenticated `POST /user-presence/heartbeat` endpoint by default. It uses the configured guard and applies a limit of 60 requests per minute. The endpoint ignores request-body timestamps and records the server's current time.

```js
await fetch('/user-presence/heartbeat', {
  method: 'POST',
  headers: {
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
  }
})
```

Configure the route in `config/user-presence.php`:

```php
'guard' => 'web',
'heartbeat' => [
    'enabled' => true,
    'path' => 'user-presence/heartbeat',
    'middleware' => ['web'],
    'rate_limit' => [
        'attempts' => 60,
        'decay_minutes' => 1,
    ],
],
```

For API authentication, select the API guard and its middleware, for example `guard => 'sanctum'` and `middleware => ['api']`. Laravel sessions and identifiable access tokens use separate activity records. Stateless clients without either can send a stable `X-Presence-Device` value to keep their devices separate.

## Request middleware

Add `UpdateLastSeen` to the web or API middleware group to record authenticated requests through the same presence service as the heartbeat endpoint:

```php
use EvanSchleret\LaravelUserPresence\Middleware\UpdateLastSeen;
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [UpdateLastSeen::class]);
})
```

The middleware is enabled by default and can be disabled with `middleware.enabled`. `activity_write_interval` limits writes per session or device; its effective value is capped below the online threshold so active requests do not age into idle solely because writes were throttled.

## Legacy column storage

Applications that already store `last_seen_at` continue using that column automatically in `auto` mode. You can also set `store` to `column` to require the column or to `cache` to opt out of it. Column storage does not require `presence_broadcast_status` for presence reads and never runs migrations. The package never changes the application's schema automatically.

```php
'store' => 'column',
```

The migration publishing workflow from v1 remains available for applications that explicitly choose column storage. New installations without `last_seen_at` use cache and do not need to publish or run those migrations. Switching from column storage to cache does not import SQL timestamps; users have no retained cache activity and appear offline until their next heartbeat or tracked request. You can keep the column in the application's schema or remove it with an application-owned migration.

The v2.1 API retains the v2.0 trait methods, accessors, events, and `user-presence:update-statuses` command. The `is_offline` accessor keeps its v2.0 inverse-of-online behavior for compatibility.

## Broadcasting

Install and configure Laravel broadcasting, then listen on the user's private channel:

```ts
Echo.private(`user-presence.${userId}`)
  .listen('.user.went.online', (event) => updatePresence(event.user_id, event.status))
  .listen('.user.went.idle', (event) => updatePresence(event.user_id, event.status))
  .listen('.user.went.offline', (event) => updatePresence(event.user_id, event.status))
```

Broadcasting remains enabled by default and can be disabled with `broadcasting.enabled`. A status transition event is emitted when the package evaluates a changed status during a read, heartbeat, or tracked request. No scheduler is needed for cache status calculation. The `user-presence:update-statuses` command remains available for applications using legacy column storage with both legacy columns; in cache mode, it exits without scanning the user table. A transition that is never evaluated does not emit an event.

By default, users can listen only to their own channel. Configure `channel_authorizer` to apply application access rules when users need to see other users' presence. Laravel uses its queue when an asynchronous queue connection is configured.

## Configuration

Publish the configuration to customize the selected guard, model, thresholds, cache, heartbeat route, middleware, or broadcasting. The default thresholds are 300 seconds online and 600 seconds idle across the code, tests, and configuration.

## Support

This package supports Laravel 12 and 13. See [CONTRIBUTING.md](CONTRIBUTING.md) to contribute and [SECURITY.md](SECURITY.md) to report a vulnerability privately.

## License

Laravel User Presence is open-source software licensed under the [MIT license](LICENSE.md).
