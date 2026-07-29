<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Dashboard Route Prefix
    |--------------------------------------------------------------------------
    | The URL prefix for the Chronos web dashboard.
    | Dashboard will be accessible at: {prefix}
    | API will be accessible at: {prefix}/api/*
    */
    'route_prefix' => '/chronos',

    /*
    |--------------------------------------------------------------------------
    | Redis Pool
    |--------------------------------------------------------------------------
    | The Redis pool name configured in config/autoload/redis.php
    */
    'redis_pool' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Redis Key Prefix
    |--------------------------------------------------------------------------
    | All Chronos keys in Redis will be prefixed with this string.
    */
    'prefix' => 'chronos:',

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Tracing
    |--------------------------------------------------------------------------
    | Capture incoming HTTP requests for latency and error rate observability.
    |
    | mode:
    |   'all'   — capture every request (high volume apps may see Redis growth)
    |   'smart' — capture only slow requests and errors (recommended default)
    |   'off'   — disable HTTP tracing entirely
    |
    | slow_threshold_ms: requests above this duration are always captured in 'smart' mode.
    | limit: max number of HTTP request records retained in Redis.
    */
    'http' => [
        'enabled'           => true,
        'mode'              => 'smart',
        'slow_threshold_ms' => 500,
        'limit'             => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Structured Log Capture
    |--------------------------------------------------------------------------
    | Capture application logs via ChronosMonologHandler.
    | To enable, add the handler to your logger configuration:
    |
    |   // config/autoload/logger.php
    |   'handlers' => [
    |       ['class' => \Chronos\Logging\ChronosMonologHandler::class],
    |   ],
    |
    | min_level: minimum Monolog level to capture ('debug','info','notice','warning','error','critical','alert','emergency')
    | limit: max number of log records retained in Redis.
    */
    'logging' => [
        'enabled'   => true,
        'min_level' => 'warning',
        'limit'     => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Query Observability
    |--------------------------------------------------------------------------
    | Slow query threshold for flagging and display in the Slow Queries tab.
    | Queries are already captured via DbQueryEventListener when a trace_id
    | exists. This threshold controls which ones appear in the dedicated view.
    */
    'queries' => [
        'slow_threshold_ms' => 100.0,
        'limit'             => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Job Observability (existing)
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'recent_jobs_limit' => 500,
        'failed_jobs_limit' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-Time Webhook & Slack Alerts
    |--------------------------------------------------------------------------
    | Dispatch JSON webhooks when error rates or failed jobs exceed thresholds.
    | Compatible with Slack, Discord, Telegram, or custom webhook receivers.
    */
    'alerts' => [
        'enabled'                     => false,
        'webhook_url'                 => '',
        'http_error_rate_threshold'  => 5.0,  // % error rate trigger
        'job_failure_threshold'       => 10,   // total failed jobs trigger
    ],
];
