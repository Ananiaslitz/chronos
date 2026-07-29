<?php

declare(strict_types=1);

namespace Chronos\Logging;

use Chronos\Storage\RedisStorage;
use Chronos\Tracing\TraceContext;
use Hyperf\Contract\ConfigInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class ChronosMonologHandler extends AbstractProcessingHandler
{
    protected bool $enabled;

    public function __construct(
        protected RedisStorage $storage,
        protected ConfigInterface $config
    ) {
        $this->enabled = (bool) $this->config->get('chronos.logging.enabled', true);

        $minLevelName = strtoupper(
            (string) $this->config->get('chronos.logging.min_level', 'warning')
        );

        $level = Level::fromName($minLevelName);

        parent::__construct($level);
    }

    protected function write(LogRecord $record): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $traceId = TraceContext::getTraceId();

            $context = $record->context;
            $extra   = $record->extra;

            $this->storage->recordLog(
                level: $record->level->getName(),
                message: $record->message,
                channel: $record->channel,
                traceId: $traceId,
                context: $context,
                extra: $extra,
                datetime: $record->datetime->format('Y-m-d H:i:s.u'),
            );
        } catch (Throwable $e) {
            // Silently skip to avoid recursive logging loops
        }
    }
}
