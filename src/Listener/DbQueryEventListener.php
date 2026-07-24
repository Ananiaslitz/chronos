<?php

declare(strict_types=1);

namespace Chronos\Listener;

use Chronos\Storage\RedisStorage;
use Chronos\Tracing\TraceContext;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Throwable;

#[Listener]
class DbQueryEventListener implements ListenerInterface
{
    public function __construct(
        protected RedisStorage $storage
    ) {}

    public function listen(): array
    {
        return [
            QueryExecuted::class,
        ];
    }

    public function process(object $event): void
    {
        if (! $event instanceof QueryExecuted) {
            return;
        }

        try {
            $traceId = TraceContext::getTraceId();
            if (empty($traceId)) {
                return;
            }

            $sql = $event->sql;
            $timeMs = sprintf('%.2f', $event->time);
            $bindings = $event->bindings ?? [];

            $spanData = [
                'id' => 'db_' . bin2hex(random_bytes(4)),
                'job_class' => 'SQL: ' . $sql,
                'type' => 'db_query',
                'sql' => $sql,
                'bindings' => json_encode($bindings, JSON_UNESCAPED_UNICODE),
                'duration_ms' => $timeMs,
                'created_at' => (string) microtime(true),
                'status' => 'completed',
            ];

            $this->storage->recordTraceSpan($traceId, $spanData);
        } catch (Throwable $e) {
            // Silently handle
        }
    }
}
