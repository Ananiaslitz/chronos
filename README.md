# 🏛️ Chronos — Lightweight Observability Platform for Hyperf

**Chronos** is a zero-configuration observability extension for the **Hyperf** framework, covering the three core observability pillars:

| Pillar | What it tracks |
|---|---|
| **Traces** | HTTP requests → queue jobs → SQL queries, all correlated by `trace_id` |
| **Metrics** | Request rate, avg latency, error rate, job throughput |
| **Logs** | Structured WARNING+ logs with trace correlation |

All data is stored in **Redis** — no external infrastructure needed.

---

## ✨ Features

- **📊 Multi-Pillar Dashboard** — Overview, HTTP, Queue Jobs, Slow Queries, and Logs in a unified SPA
- **🌐 HTTP Request Tracing** — Capture latency, status codes, and slow requests via `TraceMiddleware`
- **📦 Queue Job Monitoring** — Track start, completion, failure, retry with payload and stack traces
- **⚡ Slow Query Detection** — Auto-index DB queries exceeding a configurable threshold
- **📋 Structured Log Capture** — Monolog handler captures WARNING+ logs with trace context
- **🔗 Cross-Pillar Correlation** — Click any `trace_id` to see the full HTTP → Job → SQL waterfall
- **🎛️ Smart Capture Mode** — Only record slow/error HTTP requests to control Redis growth
- **🧹 Auto-Trim** — Configurable retention limits for all data types

---

## 📦 Installation

```bash
composer require dhsa/chronos
```

Or for local development in `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../chronos" }
]
```

Then publish the config (optional):

```bash
php bin/hyperf.php vendor:publish dhsa/chronos
```

---

## 🚀 Usage

### 1. Start your server

```bash
php bin/hyperf.php start
```

Navigate to `http://localhost:9501/chronos` — the queue and trace dashboard is already live.

### 2. Enable HTTP Tracing (optional but recommended)

Add `TraceMiddleware` to your HTTP server config:

```php
// config/autoload/middlewares.php
return [
    'http' => [
        \Chronos\Middleware\TraceMiddleware::class,
        // ... your other middlewares
    ],
];
```

This will:
- Capture HTTP requests (slow + errors by default, configurable)
- Inject `X-Chronos-Trace-Id` into responses
- Propagate the trace ID so queue jobs and SQL queries are correlated

### 3. Enable Log Capture (optional)

Add `ChronosMonologHandler` to your logger config:

```php
// config/autoload/logger.php
return [
    'default' => [
        'handler' => [
            'class'       => Monolog\Handler\StreamHandler::class,
            'constructor' => ['stream' => BASE_PATH . '/runtime/logs/hyperf.log'],
        ],
        'handlers' => [
            [
                'class'       => \Chronos\Logging\ChronosMonologHandler::class,
                'constructor' => [], // auto-wired from DI
            ],
        ],
    ],
];
```

This captures all WARNING+ logs with trace correlation.

---

## ⚙️ Configuration

```php
// config/autoload/chronos.php
return [
    'route_prefix' => '/chronos',
    'redis_pool'   => 'default',
    'prefix'       => 'chronos:',

    'http' => [
        'enabled'           => true,
        'mode'              => 'smart',  // 'all' | 'smart' | 'off'
        'slow_threshold_ms' => 500,      // ms above which a request is "slow"
        'limit'             => 1000,     // max HTTP records in Redis
    ],

    'logging' => [
        'enabled'   => true,
        'min_level' => 'warning',        // 'debug'|'info'|'warning'|'error'|...
        'limit'     => 500,
    ],

    'queries' => [
        'slow_threshold_ms' => 100.0,    // flag queries above this duration
        'limit'             => 300,
    ],

    'metrics' => [
        'recent_jobs_limit' => 500,
        'failed_jobs_limit' => 200,
    ],
];
```

---

## 🧩 TraceableJob Trait

To propagate trace IDs from HTTP requests into queue jobs:

```php
use Chronos\Tracing\TraceableJob;

class MyJob implements JobInterface
{
    use TraceableJob;

    public function __construct(public string $userId) {}

    public function handle(): void
    {
        // do work...
    }
}
```

When dispatched from within an HTTP request that passed through `TraceMiddleware`, the job will automatically inherit the HTTP trace ID.

---

## 🛡️ License

Chronos is open-sourced software licensed under the [MIT License](LICENSE).
