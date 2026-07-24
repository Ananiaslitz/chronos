<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Chronos Route Prefix
    |--------------------------------------------------------------------------
    |
    | The HTTP path prefix where the Chronos Dashboard & API will be served.
    |
    */
    'route_prefix' => '/chronos',

    /*
    |--------------------------------------------------------------------------
    | Redis Connection Pool
    |--------------------------------------------------------------------------
    |
    | The name of the Redis pool configured in `config/autoload/redis.php`.
    |
    */
    'redis_pool' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Redis Key Prefix
    |--------------------------------------------------------------------------
    |
    | Key prefix used for storing Chronos stats, jobs, and metrics in Redis.
    |
    */
    'prefix' => 'chronos:',

    /*
    |--------------------------------------------------------------------------
    | Retention Metrics Limits
    |--------------------------------------------------------------------------
    |
    | Maximum number of recent and failed jobs retained in Redis storage.
    |
    */
    'metrics' => [
        'recent_jobs_limit' => 500,
        'failed_jobs_limit' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Basic Security / Auth
    |--------------------------------------------------------------------------
    |
    | Basic authentication configuration for the Chronos dashboard.
    |
    */
    'auth' => [
        'enabled' => false,
        'username' => 'admin',
        'password' => 'chronos',
    ],
];
