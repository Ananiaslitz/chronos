<?php

declare(strict_types=1);

namespace Chronos\Storage;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use Throwable;

class RedisStorage
{
    protected string $prefix;
    protected string $pool;
    protected int $recentLimit;
    protected int $failedLimit;

    public function __construct(
        protected RedisFactory $redisFactory,
        protected ConfigInterface $config
    ) {
        $this->pool = $this->config->get('chronos.redis_pool', 'default');
        $this->prefix = $this->config->get('chronos.prefix', 'chronos:');
        $this->recentLimit = (int) $this->config->get('chronos.metrics.recent_jobs_limit', 500);
        $this->failedLimit = (int) $this->config->get('chronos.metrics.failed_jobs_limit', 200);
    }

    protected function redis()
    {
        return $this->redisFactory->get($this->pool);
    }

    public function recordStart(string $jobId, string $jobClass, string $queue, mixed $payload, int $attempts = 1): void
    {
        $now = microtime(true);
        $redis = $this->redis();

        $data = [
            'id' => $jobId,
            'job_class' => $jobClass,
            'queue' => $queue,
            'status' => 'processing',
            'payload' => is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'attempts' => (string) $attempts,
            'started_at' => (string) $now,
            'created_at' => (string) $now,
        ];

        $jobKey = $this->prefix . 'job:' . $jobId;
        $redis->hMSet($jobKey, $data);
        $redis->expire($jobKey, 86400 * 3); // 3 days retention

        $redis->hIncrBy($this->prefix . 'stats', 'total', 1);
        $redis->zAdd($this->prefix . 'recent_jobs', (int) ($now * 1000), $jobId);

        // Trim recent jobs
        $redis->zRemRangeByRank($this->prefix . 'recent_jobs', 0, -($this->recentLimit + 1));
    }

    public function recordSuccess(string $jobId, float $durationMs): void
    {
        $now = microtime(true);
        $redis = $this->redis();
        $jobKey = $this->prefix . 'job:' . $jobId;

        $redis->hMSet($jobKey, [
            'status' => 'completed',
            'finished_at' => (string) $now,
            'duration_ms' => sprintf('%.2f', $durationMs),
        ]);

        $redis->hIncrBy($this->prefix . 'stats', 'completed', 1);
    }

    public function recordFailure(string $jobId, Throwable $exception, float $durationMs): void
    {
        $now = microtime(true);
        $redis = $this->redis();
        $jobKey = $this->prefix . 'job:' . $jobId;

        $redis->hMSet($jobKey, [
            'status' => 'failed',
            'finished_at' => (string) $now,
            'duration_ms' => sprintf('%.2f', $durationMs),
            'exception_message' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'exception_trace' => $exception->getTraceAsString(),
        ]);

        $redis->hIncrBy($this->prefix . 'stats', 'failed', 1);
        $redis->zAdd($this->prefix . 'failed_jobs', (int) ($now * 1000), $jobId);

        // Trim failed jobs
        $redis->zRemRangeByRank($this->prefix . 'failed_jobs', 0, -($this->failedLimit + 1));
    }

    public function recordRetry(string $jobId): void
    {
        $redis = $this->redis();
        $redis->hIncrBy($this->prefix . 'stats', 'retried', 1);
        $redis->zRem($this->prefix . 'failed_jobs', $jobId);

        $jobKey = $this->prefix . 'job:' . $jobId;
        $redis->hSet($jobKey, 'status', 'retried');
    }

    public function getStats(): array
    {
        $redis = $this->redis();
        $stats = $redis->hGetAll($this->prefix . 'stats') ?: [];

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'completed' => (int) ($stats['completed'] ?? 0),
            'failed' => (int) ($stats['failed'] ?? 0),
            'retried' => (int) ($stats['retried'] ?? 0),
        ];
    }

    public function getRecentJobs(int $limit = 50): array
    {
        $redis = $this->redis();
        $jobIds = $redis->zRevRange($this->prefix . 'recent_jobs', 0, $limit - 1) ?: [];

        $jobs = [];
        foreach ($jobIds as $jobId) {
            $jobData = $this->getJob($jobId);
            if ($jobData) {
                $jobs[] = $jobData;
            }
        }

        return $jobs;
    }

    public function getFailedJobs(int $limit = 50): array
    {
        $redis = $this->redis();
        $jobIds = $redis->zRevRange($this->prefix . 'failed_jobs', 0, $limit - 1) ?: [];

        $jobs = [];
        foreach ($jobIds as $jobId) {
            $jobData = $this->getJob($jobId);
            if ($jobData) {
                $jobs[] = $jobData;
            }
        }

        return $jobs;
    }

    public function getJob(string $jobId): ?array
    {
        $redis = $this->redis();
        $jobData = $redis->hGetAll($this->prefix . 'job:' . $jobId);

        if (empty($jobData)) {
            return null;
        }

        return $jobData;
    }

    public function deleteJob(string $jobId): bool
    {
        $redis = $this->redis();
        $redis->del($this->prefix . 'job:' . $jobId);
        $redis->zRem($this->prefix . 'recent_jobs', $jobId);
        $redis->zRem($this->prefix . 'failed_jobs', $jobId);

        return true;
    }

    public function clearFailedJobs(): int
    {
        $redis = $this->redis();
        $jobIds = $redis->zRange($this->prefix . 'failed_jobs', 0, -1) ?: [];
        $count = count($jobIds);

        foreach ($jobIds as $jobId) {
            $redis->del($this->prefix . 'job:' . $jobId);
        }

        $redis->del($this->prefix . 'failed_jobs');

        return $count;
    }
}
