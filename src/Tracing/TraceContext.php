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
            try {
                $job->__chronos_trace_id = $traceId;
            } catch (\Throwable $e) {
                // Ignore if dynamic property not supported
            }
        }

        return $job;
    }
}
