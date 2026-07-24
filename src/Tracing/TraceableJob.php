<?php

declare(strict_types=1);

namespace Chronos\Tracing;

trait TraceableJob
{
    public ?string $chronos_trace_id = null;
}
