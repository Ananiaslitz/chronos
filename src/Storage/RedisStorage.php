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
        $limitRecent = (int) $this->config->get('chronos.metrics.recent_jobs_limit', 500);
        $limitFailed = (int) $this->config->get('chronos.metrics.failed_jobs_limit', 200);

        $this->recentLimit = $limitRecent > 0 ? $limitRecent : 500;
        $this->failedLimit = $limitFailed > 0 ? $limitFailed : 200;
    }

    protected function redis()
    {
        return $this->redisFactory->get($this->pool);
    }

    public function recordStart(string $jobId, string $jobClass, string $queue, mixed $payload, int $attempts = 1, array $meta = []): void
    {
        $now = microtime(true);
        $redis = $this->redis();
        $jobKey = $this->prefix . 'job:' . $jobId;

        $existing = $redis->hGetAll($jobKey) ?: [];
        $createdAt = isset($existing['created_at']) ? (float) $existing['created_at'] : $now;
        $waitTimeMs = sprintf('%.2f', max(0, ($now - $createdAt) * 1000));

        $data = [
            'id' => $jobId,
            'job_class' => $jobClass,
            'queue' => $queue,
            'status' => 'processing',
            'payload' => is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'attempts' => (string) $attempts,
            'started_at' => (string) $now,
            'created_at' => (string) $createdAt,
            'wait_time_ms' => $waitTimeMs,
            'trace_id' => $meta['trace_id'] ?? ($existing['trace_id'] ?? ''),
            'pid' => (string) ($meta['pid'] ?? getmypid()),
            'hostname' => (string) ($meta['hostname'] ?? gethostname()),
            'memory_peak_mb' => sprintf('%.2f MB', (memory_get_peak_usage(true) / 1024 / 1024)),
            'tags' => is_array($meta['tags'] ?? null) ? json_encode($meta['tags'], JSON_UNESCAPED_UNICODE) : ($existing['tags'] ?? '[]'),
        ];

        $redis->hMSet($jobKey, $data);
        $redis->expire($jobKey, 86400 * 3);

        $redis->hIncrBy($this->prefix . 'stats', 'total', 1);
        $redis->zAdd($this->prefix . 'recent_jobs', (float) $now, $jobId);

        if (! empty($data['trace_id'])) {
            $redis->zAdd($this->prefix . 'trace:' . $data['trace_id'], (float) $now, $jobId);
            $redis->expire($this->prefix . 'trace:' . $data['trace_id'], 86400 * 3);
        }

        $this->trimRecentJobs($redis);
    }

    public function recordSuccess(string $jobId, string $jobClass, string $queue, mixed $payload, float $durationMs, array $meta = []): void
    {
        $now = microtime(true);
        $redis = $this->redis();
        $jobKey = $this->prefix . 'job:' . $jobId;

        $existing = $redis->hGetAll($jobKey) ?: [];
        $createdAt = isset($existing['created_at']) ? (float) $existing['created_at'] : $now;
        $startedAt = isset($existing['started_at']) ? (float) $existing['started_at'] : $now;
        $waitTimeMs = sprintf('%.2f', max(0, ($startedAt - $createdAt) * 1000));

        $data = [
            'id' => $jobId,
            'job_class' => $existing['job_class'] ?? $jobClass,
            'queue' => $existing['queue'] ?? $queue,
            'status' => 'completed',
            'payload' => $existing['payload'] ?? (is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            'finished_at' => (string) $now,
            'started_at' => (string) $startedAt,
            'created_at' => (string) $createdAt,
            'duration_ms' => sprintf('%.2f', $durationMs),
            'wait_time_ms' => $waitTimeMs,
            'trace_id' => $meta['trace_id'] ?? ($existing['trace_id'] ?? ''),
            'pid' => (string) ($meta['pid'] ?? ($existing['pid'] ?? getmypid())),
            'hostname' => (string) ($meta['hostname'] ?? ($existing['hostname'] ?? gethostname())),
            'memory_peak_mb' => sprintf('%.2f MB', (memory_get_peak_usage(true) / 1024 / 1024)),
            'tags' => is_array($meta['tags'] ?? null) ? json_encode($meta['tags'], JSON_UNESCAPED_UNICODE) : ($existing['tags'] ?? '[]'),
        ];

        $redis->hMSet($jobKey, $data);
        $redis->expire($jobKey, 86400 * 3);

        $redis->hIncrBy($this->prefix . 'stats', 'completed', 1);
        $redis->zAdd($this->prefix . 'recent_jobs', (float) $now, $jobId);

        if (! empty($data['trace_id'])) {
            $redis->zAdd($this->prefix . 'trace:' . $data['trace_id'], (float) $now, $jobId);
            $redis->expire($this->prefix . 'trace:' . $data['trace_id'], 86400 * 3);
        }

        $this->trimRecentJobs($redis);
    }

    public function recordFailure(string $jobId, string $jobClass, string $queue, mixed $payload, Throwable $exception, float $durationMs, array $meta = []): void
    {
        $now = microtime(true);
        $redis = $this->redis();
        $jobKey = $this->prefix . 'job:' . $jobId;

        $existing = $redis->hGetAll($jobKey) ?: [];
        $createdAt = isset($existing['created_at']) ? (float) $existing['created_at'] : $now;
        $startedAt = isset($existing['started_at']) ? (float) $existing['started_at'] : $now;
        $waitTimeMs = sprintf('%.2f', max(0, ($startedAt - $createdAt) * 1000));

        $data = [
            'id' => $jobId,
            'job_class' => $existing['job_class'] ?? $jobClass,
            'queue' => $existing['queue'] ?? $queue,
            'status' => 'failed',
            'payload' => $existing['payload'] ?? (is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            'finished_at' => (string) $now,
            'started_at' => (string) $startedAt,
            'created_at' => (string) $createdAt,
            'duration_ms' => sprintf('%.2f', $durationMs),
            'wait_time_ms' => $waitTimeMs,
            'trace_id' => $meta['trace_id'] ?? ($existing['trace_id'] ?? ''),
            'pid' => (string) ($meta['pid'] ?? ($existing['pid'] ?? getmypid())),
            'hostname' => (string) ($meta['hostname'] ?? ($existing['hostname'] ?? gethostname())),
            'memory_peak_mb' => sprintf('%.2f MB', (memory_get_peak_usage(true) / 1024 / 1024)),
            'tags' => is_array($meta['tags'] ?? null) ? json_encode($meta['tags'], JSON_UNESCAPED_UNICODE) : ($existing['tags'] ?? '[]'),
            'exception_message' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'exception_trace' => $exception->getTraceAsString(),
        ];

        $redis->hMSet($jobKey, $data);
        $redis->expire($jobKey, 86400 * 3);

        $redis->hIncrBy($this->prefix . 'stats', 'failed', 1);
        $redis->zAdd($this->prefix . 'recent_jobs', (float) $now, $jobId);
        $redis->zAdd($this->prefix . 'failed_jobs', (float) $now, $jobId);

        if (! empty($data['trace_id'])) {
            $redis->zAdd($this->prefix . 'trace:' . $data['trace_id'], (float) $now, $jobId);
            $redis->expire($this->prefix . 'trace:' . $data['trace_id'], 86400 * 3);
        }

        $this->trimRecentJobs($redis);
        $this->trimFailedJobs($redis);
    }

    protected function trimRecentJobs($redis): void
    {
        $limit = $this->recentLimit > 0 ? $this->recentLimit : 500;
        $card = (int) $redis->zCard($this->prefix . 'recent_jobs');
        if ($card > $limit) {
            $redis->zRemRangeByRank($this->prefix . 'recent_jobs', 0, $card - $limit - 1);
        }
    }

    protected function trimFailedJobs($redis): void
    {
        $limit = $this->failedLimit > 0 ? $this->failedLimit : 200;
        $card = (int) $redis->zCard($this->prefix . 'failed_jobs');
        if ($card > $limit) {
            $redis->zRemRangeByRank($this->prefix . 'failed_jobs', 0, $card - $limit - 1);
        }
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

        $completed = (int) ($stats['completed'] ?? 0);
        $failed = (int) ($stats['failed'] ?? 0);
        $retried = (int) ($stats['retried'] ?? 0);
        $totalRaw = (int) ($stats['total'] ?? 0);
        $total = max($totalRaw, $completed + $failed);

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'retried' => $retried,
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

    public function getTraceJobs(string $traceId): array
    {
        $redis = $this->redis();
        $jobIds = $redis->zRange($this->prefix . 'trace:' . $traceId, 0, -1) ?: [];

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
