<?php

declare(strict_types=1);

namespace Chronos\Tracing;

use Hyperf\Context\Context;

class TraceContext
{
    protected const TRACE_KEY = 'chronos.trace_id';
    protected const PARENT_KEY = 'chronos.parent_job_id';

    public static function getTraceId(): string
    {
        if (class_exists(Context::class) && Context::has(self::TRACE_KEY)) {
            return (string) Context::get(self::TRACE_KEY);
        }

        return self::generateTraceId();
    }

    public static function setTraceId(string $traceId): void
    {
        if (class_exists(Context::class)) {
            Context::set(self::TRACE_KEY, $traceId);
        }
    }

    public static function getParentJobId(): ?string
    {
        if (class_exists(Context::class) && Context::has(self::PARENT_KEY)) {
            return (string) Context::get(self::PARENT_KEY);
        }

        return null;
    }

    public static function setParentJobId(?string $jobId): void
    {
        if (class_exists(Context::class)) {
            Context::set(self::PARENT_KEY, $jobId);
        }
    }

    public static function generateTraceId(): string
    {
        $traceId = 'trc_' . bin2hex(random_bytes(8));
        self::setTraceId($traceId);
        return $traceId;
    }

    public static function propagate(object $job): object
    {
        $traceId = self::getTraceId();
        if (! empty($traceId)) {
            if (property_exists($job, 'chronos_trace_id')) {
                $job->chronos_trace_id = $traceId;
            } elseif (property_exists($job, '__chronos_trace_id')) {
                $job->__chronos_trace_id = $traceId;
            } else {
                try {
                    @$job->chronos_trace_id = $traceId;
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
        }

        return $job;
    }

    /**
     * Records a custom span (e.g. db_query, redis, external) into the current trace.
     */
    public static function recordSpan(
        string $type,
        string $name,
        float $durationMs = 0.0,
        array $meta = []
    ): void {
        $traceId = self::getTraceId();
        if (empty($traceId)) {
            return;
        }

        try {
            if (class_exists(\Hyperf\Context\ApplicationContext::class) && \Hyperf\Context\ApplicationContext::hasContainer()) {
                $container = \Hyperf\Context\ApplicationContext::getContainer();
                if ($container->has(\Chronos\Storage\RedisStorage::class)) {
                    $storage = $container->get(\Chronos\Storage\RedisStorage::class);
                    $spanData = array_merge([
                        'id'          => $type . '_' . bin2hex(random_bytes(4)),
                        'job_class'   => $name,
                        'type'        => $type,
                        'sql'         => $name,
                        'duration_ms' => sprintf('%.2f', $durationMs),
                        'created_at'  => (string) microtime(true),
                        'status'      => 'completed',
                    ], $meta);

                    $storage->recordTraceSpan($traceId, $spanData);
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore
        }
    }
}
