<?php

declare(strict_types=1);

namespace Chronos\Middleware;

use Chronos\Storage\RedisStorage;
use Chronos\Tracing\TraceContext;
use Hyperf\Contract\ConfigInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class TraceMiddleware implements MiddlewareInterface
{
    protected bool $enabled;
    protected string $mode;
    protected float $slowThresholdMs;

    public function __construct(
        protected RedisStorage $storage,
        protected ConfigInterface $config
    ) {
        $this->enabled         = (bool) $this->config->get('chronos.http.enabled', true);
        $this->mode            = (string) $this->config->get('chronos.http.mode', 'smart');
        $this->slowThresholdMs = (float) $this->config->get('chronos.http.slow_threshold_ms', 500);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $this->enabled || $this->mode === 'off') {
            return $handler->handle($request);
        }

        $traceId = $this->extractIncomingTraceId($request);
        $start   = microtime(true);

        // Propagate trace ID into the coroutine context so jobs/queries can correlate
        TraceContext::setTraceId($traceId);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $start) * 1000;
            $this->maybeRecord($traceId, $request, 500, $durationMs, $e->getMessage());
            throw $e;
        }

        $durationMs = (microtime(true) - $start) * 1000;
        $statusCode = $response->getStatusCode();

        $this->maybeRecord($traceId, $request, $statusCode, $durationMs);

        return $response->withHeader('X-Chronos-Trace-Id', $traceId);
    }

    protected function extractIncomingTraceId(ServerRequestInterface $request): string
    {
        // 1. Check W3C traceparent header: 00-{traceId}-{spanId}-{flags}
        if ($request->hasHeader('traceparent')) {
            $header = $request->getHeaderLine('traceparent');
            $parts  = explode('-', trim($header));
            if (count($parts) >= 3 && ! empty($parts[1])) {
                return 'w3c_' . $parts[1];
            }
        }

        // 2. Check X-Chronos-Trace-Id / X-Trace-Id / X-Correlation-Id
        foreach (['x-chronos-trace-id', 'x-trace-id', 'x-correlation-id'] as $headerName) {
            if ($request->hasHeader($headerName)) {
                $val = trim($request->getHeaderLine($headerName));
                if (! empty($val)) {
                    return $val;
                }
            }
        }

        return 'http_' . bin2hex(random_bytes(8));
    }

    protected function maybeRecord(
        string $traceId,
        ServerRequestInterface $request,
        int $statusCode,
        float $durationMs,
        string $errorMessage = ''
    ): void {
        try {
            $shouldCapture = match ($this->mode) {
                'all'   => true,
                'smart' => $durationMs >= $this->slowThresholdMs || $statusCode >= 400,
                default => false,
            };

            if (! $shouldCapture) {
                return;
            }

            $uri    = (string) $request->getUri();
            $path   = $request->getUri()->getPath();
            $method = strtoupper($request->getMethod());
            $ip     = $this->resolveIp($request);

            $this->storage->recordHttpRequest($traceId, [
                'trace_id'        => $traceId,
                'method'          => $method,
                'uri'             => $uri,
                'path'            => $path,
                'status_code'     => (string) $statusCode,
                'duration_ms'     => sprintf('%.2f', $durationMs),
                'memory_peak_mb'  => sprintf('%.2f MB', memory_get_peak_usage(true) / 1024 / 1024),
                'ip'              => $ip,
                'is_slow'         => $durationMs >= $this->slowThresholdMs ? '1' : '0',
                'error_message'   => $errorMessage,
                'created_at'      => (string) microtime(true),
            ]);
        } catch (Throwable $e) {
            // Never let observability code break the application
        }
    }

    protected function resolveIp(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();

        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (! empty($params[$key])) {
                return explode(',', (string) $params[$key])[0];
            }
        }

        return 'unknown';
    }
}
