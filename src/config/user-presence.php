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
