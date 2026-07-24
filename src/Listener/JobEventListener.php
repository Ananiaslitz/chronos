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
use ReflectionProperty;
use Throwable;
use WeakMap;

#[Listener]
class JobEventListener implements ListenerInterface
{
    /**
     * Map of active job execution start times keyed by unique job identifier.
     */
    protected array $startTimes = [];

    /**
     * WeakMap mapping job/message object instances to generated unique Chronos IDs.
     */
    protected WeakMap $jobIdMap;

    public function __construct(
        protected RedisStorage $storage
    ) {
        $this->jobIdMap = new WeakMap();
    }

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
        } catch (Throwable $e) {
            error_log('[Chronos Listener Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    protected function handleBefore(BeforeHandle $event): void
    {
        $job = $this->getJob($event);
        $jobId = $this->getJobId($event);
        $jobClass = $job ? get_class($job) : 'UnknownJob';
        $queue = 'default';

        $this->startTimes[$jobId] = microtime(true);

        $payload = $job && method_exists($job, '__serialize') ? $job->__serialize() : (array) $job;

        $this->storage->recordStart($jobId, $jobClass, $queue, $payload);
    }

    protected function handleAfter(AfterHandle $event): void
    {
        $job = $this->getJob($event);
        $jobId = $this->getJobId($event);
        $jobClass = $job ? get_class($job) : 'UnknownJob';
        $queue = 'default';
        $payload = $job && method_exists($job, '__serialize') ? $job->__serialize() : (array) $job;

        $startTime = $this->startTimes[$jobId] ?? microtime(true);
        $durationMs = (microtime(true) - $startTime) * 1000;

        unset($this->startTimes[$jobId]);

        $this->storage->recordSuccess($jobId, $jobClass, $queue, $payload, $durationMs);
    }

    protected function handleFailed(FailedHandle $event): void
    {
        $job = $this->getJob($event);
        $jobId = $this->getJobId($event);
        $jobClass = $job ? get_class($job) : 'UnknownJob';
        $queue = 'default';
        $payload = $job && method_exists($job, '__serialize') ? $job->__serialize() : (array) $job;

        $startTime = $this->startTimes[$jobId] ?? microtime(true);
        $durationMs = (microtime(true) - $startTime) * 1000;

        unset($this->startTimes[$jobId]);

        $throwable = method_exists($event, 'getThrowable') ? $event->getThrowable() : new \Exception('Unknown job error');

        $this->storage->recordFailure($jobId, $jobClass, $queue, $payload, $throwable, $durationMs);
    }

    protected function handleRetry(RetryHandle $event): void
    {
        $jobId = $this->getJobId($event);
        $this->storage->recordRetry($jobId);
    }

    protected function getMessage(object $event): ?object
    {
        if (method_exists($event, 'getMessage')) {
            return $event->getMessage();
        }

        return null;
    }

    protected function getJob(object $event): ?object
    {
        $message = $this->getMessage($event);
        if (! $message) {
            return null;
        }

        if (method_exists($message, 'job')) {
            return $message->job();
        }

        if (property_exists($message, 'job')) {
            try {
                $ref = new ReflectionProperty($message, 'job');
                $ref->setAccessible(true);
                return $ref->getValue($message);
            } catch (Throwable $e) {
                // Ignore reflection error
            }
        }

        return null;
    }

    protected function getJobId(object $event): string
    {
        $message = $this->getMessage($event);

        $job = $this->getJob($event);

        if ($job) {
            if (property_exists($job, 'id') && $job->id) {
                return (string) $job->id;
            }

            if (! isset($this->jobIdMap[$job])) {
                $this->jobIdMap[$job] = md5(get_class($job) . ':' . microtime(true) . ':' . rand(1000, 9999));
            }

            return $this->jobIdMap[$job];
        }

        if ($message) {
            if (! isset($this->jobIdMap[$message])) {
                $this->jobIdMap[$message] = md5(get_class($message) . ':' . microtime(true) . ':' . rand(1000, 9999));
            }

            return $this->jobIdMap[$message];
        }

        return md5(get_class($event) . ':' . spl_object_hash($event));
    }
}
