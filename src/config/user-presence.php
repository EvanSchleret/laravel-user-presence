<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guard name
    |--------------------------------------------------------------------------
    |
    | Specify which auth guard to use when tracking user presence.
    | Leave null to use the default guard (Auth::user()).
    |
    */
    'guard' => env('LARAVEL_USER_PRESENCE_GUARD', 'web'),

    'model' => env('LARAVEL_USER_PRESENCE_MODEL'),

    'channel_authorizer' => env('LARAVEL_USER_PRESENCE_CHANNEL_AUTHORIZER'),

    'store' => env('LARAVEL_USER_PRESENCE_STORE', 'auto'),

    'cache_store' => env('LARAVEL_USER_PRESENCE_CACHE_STORE'),

    'cache_prefix' => env('LARAVEL_USER_PRESENCE_CACHE_PREFIX', 'laravel-user-presence'),

    'cache_retention' => env('LARAVEL_USER_PRESENCE_CACHE_RETENTION', 86400),

    'activity_write_interval' => env('LARAVEL_USER_PRESENCE_WRITE_INTERVAL', 60),

    'middleware' => [
        'enabled' => env('LARAVEL_USER_PRESENCE_MIDDLEWARE_ENABLED', true),
    ],

    'heartbeat' => [
        'enabled' => env('LARAVEL_USER_PRESENCE_HEARTBEAT_ENABLED', true),
        'path' => env('LARAVEL_USER_PRESENCE_HEARTBEAT_PATH', 'user-presence/heartbeat'),
        'middleware' => ['web'],
        'rate_limit' => [
            'attempts' => env('LARAVEL_USER_PRESENCE_HEARTBEAT_RATE_LIMIT', 60),
            'decay_minutes' => env('LARAVEL_USER_PRESENCE_HEARTBEAT_RATE_DECAY', 1),
        ],
    ],

    'broadcasting' => [
        'enabled' => env('LARAVEL_USER_PRESENCE_BROADCASTING_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Online Threshold (in seconds)
    |--------------------------------------------------------------------------
    |
    | If the user has been seen within this number of seconds,
    | they will be considered "online".
    |
    */
    'online_threshold' => env('LARAVEL_USER_PRESENCE_ONLINE_THRESHOLD', 300),

    /*
    |--------------------------------------------------------------------------
    | Idle Threshold (in seconds)
    |--------------------------------------------------------------------------
    |
    | If the user has not been seen for more than "online_threshold"
    | seconds but less than this value, they are considered "idle".
    |
    */
    'idle_threshold' => env('LARAVEL_USER_PRESENCE_IDLE_THRESHOLD', 600),
];
