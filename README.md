# Chronos — Observability Platform for Hyperf

Chronos is a zero-external-dependency observability platform built specifically for the **Hyperf** framework. It unifies the three core pillars of application observability using **Redis** as the single storage engine:

| Pillar | Description |
|---|---|
| **Traces** | Correlated HTTP requests, Redis commands, SQL queries, external API calls, and queue jobs |
| **Metrics** | Real-time request rates, latency distributions, error rates, queue throughput, and OpenMetrics exporter |
| **Logs** | Structured PSR-3 / Monolog log capture correlated directly with trace IDs |

---

## Features

- **Multi-Pillar Dashboard** — Single-page application providing Overview, HTTP Requests, Queue Jobs, Slow Queries, and Application Logs views.
- **Inbound & Outbound HTTP Tracing** — Middleware for incoming server requests (`TraceMiddleware`) and outbound Guzzle client calls (`ChronosGuzzleMiddleware`).
- **W3C Trace Context Support** — Native recognition and propagation of standard `traceparent` and `X-Chronos-Trace-Id` headers across microservices and API Gateways.
- **Distributed Trace Waterfall** — Visual trace waterfall connecting HTTP entry points, Redis operations, SQL execution, external HTTP calls, and async queue jobs.
- **Queue Job Monitoring** — Complete lifecycle tracking (start, completion, failure, retry) with payload inspection, stack traces, and batch retry capabilities.
- **Slow Query Detection** — Automatic indexing and threshold-based collection of database queries.
- **Structured Log Correlation** — PSR-3 compliant Monolog handler that automatically links WARNING+ logs to active trace IDs.
- **Prometheus Metrics Exporter** — Exposes `/chronos/metrics` in standard OpenMetrics plain text format for scraping by Prometheus or Grafana.
- **Real-Time Webhook Alerts** — Dispatch JSON webhooks to Slack, Discord, Telegram, or custom endpoints when error rates or failure counts cross thresholds.

---

## Installation

Install the package via Composer:

```bash
composer require dhsa/chronos
```

Publish the configuration file:

```bash
php bin/hyperf.php vendor:publish dhsa/chronos
```

---

## Integration

### 1. Inbound HTTP Tracing Middleware

Register `TraceMiddleware` in your HTTP server middleware pipeline:

```php
// config/autoload/middlewares.php
return [
    'http' => [
        \Chronos\Middleware\TraceMiddleware::class,
    ],
];
```

### 2. Outbound Guzzle Client Tracing

Attach `ChronosGuzzleMiddleware` to your Guzzle handler stack to automatically trace outgoing HTTP requests and propagate trace headers:

```php
use Chronos\Middleware\ChronosGuzzleMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$stack = HandlerStack::create();
$stack->push(new ChronosGuzzleMiddleware());

$client = new Client(['handler' => $stack]);
$response = $client->get('https://api.external.com/v1/resource');
```

### 3. Log Correlation Handler

Add `ChronosMonologHandler` to your Monolog channels:

```php
// config/autoload/logger.php
return [
    'default' => [
        'handlers' => [
            [
                'class'       => \Chronos\Logging\ChronosMonologHandler::class,
                'constructor' => [],
            ],
        ],
    ],
];
```

### 4. Custom Span Recording

Record custom spans (Redis, Database, External) anywhere in your application:

```php
use Chronos\Tracing\TraceContext;

// Record a Redis cache operation
TraceContext::recordSpan('redis', 'HGETALL session:usr_99812', 1.2);

// Record a custom database query
TraceContext::recordSpan('db_query', 'SELECT * FROM products WHERE id = 101', 14.5);

// Record an external service call
TraceContext::recordSpan('external', 'POST https://api.stripe.com/v1/charges', 185.0);
```

---

## Configuration

The published configuration file resides at `config/autoload/chronos.php`:

```php
return [
    'route_prefix' => '/chronos',
    'redis_pool'   => 'default',
    'prefix'       => 'chronos:',

    'http' => [
        'enabled'           => true,
        'mode'              => 'smart',  // 'all' | 'smart' | 'off'
        'slow_threshold_ms' => 500,
        'limit'             => 1000,
    ],

    'logging' => [
        'enabled'   => true,
        'min_level' => 'warning',
        'limit'     => 500,
    ],

    'queries' => [
        'slow_threshold_ms' => 100.0,
        'limit'             => 300,
    ],

    'metrics' => [
        'recent_jobs_limit' => 500,
        'failed_jobs_limit' => 200,
    ],

    'alerts' => [
        'enabled'                    => false,
        'webhook_url'                => '',
        'http_error_rate_threshold' => 5.0,
        'job_failure_threshold'      => 10,
    ],
];
```

---

## Prometheus Metrics

Chronos exposes an OpenMetrics-compatible endpoint at `/chronos/metrics`:

```text
# HELP chronos_http_requests_total Total number of HTTP requests tracked by Chronos.
# TYPE chronos_http_requests_total counter
chronos_http_requests_total 337

# HELP chronos_jobs_processed_total Total queue jobs processed by status.
# TYPE chronos_jobs_processed_total counter
chronos_jobs_processed_total{status="completed"} 480
chronos_jobs_processed_total{status="failed"} 354
```

---

## TraceableJob Trait

To automatically propagate trace context from HTTP requests into queue jobs:

```php
use Chronos\Tracing\TraceableJob;

class MyJob implements JobInterface
{
    use TraceableJob;

    public function __construct(public string $userId) {}

    public function handle(): void
    {
        // Job execution context inherits parent HTTP trace ID
    }
}
```

---

## License

Chronos is open-sourced software licensed under the [MIT License](LICENSE).
