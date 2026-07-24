<?php

declare(strict_types=1);

namespace Chronos\Listener;

use Chronos\Storage\RedisStorage;
use Hyperf\AsyncQueue\Event\AfterHandle;
use Hyperf\AsyncQueue\Event\BeforeHandle;
use Hyperf\AsyncQueue\Event\FailedHandle;
use Hyperf\AsyncQueue\Event\RetryHandle;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;

#[Listener]
class JobEventListener implements ListenerInterface
{
    /**
     * Map of active job execution start times keyed by unique job identifier.
     */
    protected array $startTimes = [];

    public function __construct(
        protected RedisStorage $storage
    ) {}

    public function listen(): array
    {
        return [
            BeforeHandle::class,
            AfterHandle::class,
            FailedHandle::class,
            RetryHandle::class,
        ];
    }

    public function process(object $event): void
    {
        try {
            if ($event instanceof BeforeHandle) {
                $this->handleBefore($event);
            } elseif ($event instanceof AfterHandle) {
                $this->handleAfter($event);
            } elseif ($event instanceof FailedHandle) {
                $this->handleFailed($event);
            } elseif ($event instanceof RetryHandle) {
                $this->handleRetry($event);
            }
        } catch (\Throwable $e) {
            // Silently prevent Chronos internal storage errors from failing the application queue
        }
    }

    protected function handleBefore(BeforeHandle $event): void
    {
        $job = $event->getMessage()->job ?? $event->job ?? null;
        $jobId = $this->getJobId($event);
        $jobClass = $job ? get_class($job) : 'UnknownJob';
        $queue = method_exists($event->getMessage(), 'getQueue') ? $event->getMessage()->getQueue() : 'default';

        $this->startTimes[$jobId] = microtime(true);

        $payload = method_exists($job, '__serialize') ? $job->__serialize() : (array) $job;

        $this->storage->recordStart($jobId, $jobClass, $queue, $payload);
    }

    protected function handleAfter(AfterHandle $event): void
    {
        $jobId = $this->getJobId($event);
        $startTime = $this->startTimes[$jobId] ?? microtime(true);
        $durationMs = (microtime(true) - $startTime) * 1000;

        unset($this->startTimes[$jobId]);

        $this->storage->recordSuccess($jobId, $durationMs);
    }

    protected function handleFailed(FailedHandle $event): void
    {
        $jobId = $this->getJobId($event);
        $startTime = $this->startTimes[$jobId] ?? microtime(true);
        $durationMs = (microtime(true) - $startTime) * 1000;

        unset($this->startTimes[$jobId]);

        $this->storage->recordFailure($jobId, $event->getThrowable(), $durationMs);
    }

    protected function handleRetry(RetryHandle $event): void
    {
        $jobId = $this->getJobId($event);
        $this->storage->recordRetry($jobId);
    }

    protected function getJobId(object $event): string
    {
        $message = method_exists($event, 'getMessage') ? $event->getMessage() : null;

        if ($message && method_exists($message, 'getId') && $message->getId()) {
            return (string) $message->getId();
        }

        $job = $message->job ?? ($event->job ?? null);
        if ($job && property_exists($job, 'id') && $job->id) {
            return (string) $job->id;
        }

        return md5(get_class($event) . spl_object_hash($event));
    }
}
