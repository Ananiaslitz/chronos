<?php

declare(strict_types=1);

namespace Chronos\Tracing;

#[\AllowDynamicProperties]
trait TraceableJob
{
    public ?string $chronos_trace_id = null;
}
