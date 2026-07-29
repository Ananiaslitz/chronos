<?php

declare(strict_types=1);

namespace Chronos\Middleware;

use Chronos\Tracing\TraceContext;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ChronosGuzzleMiddleware
{
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $traceId = TraceContext::getTraceId();

            if (! empty($traceId)) {
                // Propagate trace headers to outgoing request
                $cleanTraceId = preg_replace('/[^a-f0-9]/i', '', $traceId);
                $paddedTraceId = str_pad(substr($cleanTraceId, 0, 32), 32, '0');

                $request = $request
                    ->withHeader('X-Chronos-Trace-Id', $traceId)
                    ->withHeader('traceparent', sprintf('00-%s-0000000000000000-01', str_lower($paddedTraceId)));
            }

            $start  = microtime(true);
            $method = strtoupper($request->getMethod());
            $uri    = (string) $request->getUri();

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($method, $uri, $start) {
                    $durationMs = (microtime(true) - $start) * 1000;
                    $statusCode = $response->getStatusCode();

                    TraceContext::recordSpan('external', "{$method} {$uri}", $durationMs, [
                        'status_code' => (string) $statusCode,
                    ]);

                    return $response;
                },
                function ($reason) use ($method, $uri, $start) {
                    $durationMs = (microtime(true) - $start) * 1000;
                    $message    = $reason instanceof \Throwable ? $reason->getMessage() : 'Guzzle request failed';

                    TraceContext::recordSpan('external', "{$method} {$uri}", $durationMs, [
                        'status_code'   => '500',
                        'error_message' => $message,
                    ]);

                    return Create::rejectionFor($reason);
                }
            );
        };
    }
}
