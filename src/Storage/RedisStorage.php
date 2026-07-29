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
    protected int $httpLimit;
    protected int $logLimit;
    protected float $slowQueryThresholdMs;
    protected int $slowQueryLimit;

    public function __construct(
        protected RedisFactory $redisFactory,
        protected ConfigInterface $config
    ) {
        $this->pool   = $this->config->get('chronos.redis_pool', 'default');
        $this->prefix = $this->config->get('chronos.prefix', 'chronos:');

        $limitRecent = (int) $this->config->get('chronos.metrics.recent_jobs_limit', 500);
        $limitFailed = (int) $this->config->get('chronos.metrics.failed_jobs_limit', 200);

        $this->recentLimit = $limitRecent > 0 ? $limitRecent : 500;
        $this->failedLimit = $limitFailed > 0 ? $limitFailed : 200;

        $this->httpLimit            = (int) $this->config->get('chronos.http.limit', 1000);
        $this->logLimit             = (int) $this->config->get('chronos.logging.limit', 500);
        $this->slowQueryThresholdMs = (float) $this->config->get('chronos.queries.slow_threshold_ms', 100.0);
        $this->slowQueryLimit       = (int) $this->config->get('chronos.queries.limit', 300);
    }

    protected function redis()
    {
        return $this->redisFactory->get($this->pool);
    }

    // =========================================================================
    // Queue Job Methods (existing)
    // =========================================================================

    public function recordStart(string $jobId, string $jobClass, string $queue, mixed $payload, int $attempts = 1, array $meta = []): void
    {
        $now    = microtime(true);
        $redis  = $this->redis();
        $jobKey = $this->prefix . 'job:' . $jobId;

        $existing   = $redis->hGetAll($jobKey) ?: [];
        $createdAt  = isset($existing['created_at']) ? (float) $existing['created_at'] : $now;
        $waitTimeMs = sprintf('%.2f', max(0, ($now - $createdAt) * 1000));

        $data = [
            'id'              => $jobId,
            'job_class'       => $jobClass,
            'queue'           => $queue,
            'status'          => 'processing',
            'payload'         => is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'attempts'        => (string) $attempts,
            'started_at'      => (string) $now,
            'created_at'      => (string) $createdAt,
            'wait_time_ms'    => $waitTimeMs,
            'trace_id'        => $meta['trace_id'] ?? ($existing['trace_id'] ?? ''),
            'pid'             => (string) ($meta['pid'] ?? getmypid()),
            'hostname'        => (string) ($meta['hostname'] ?? gethostname()),
            'memory_peak_mb'  => sprintf('%.2f MB', (memory_get_peak_usage(true) / 1024 / 1024)),
            'tags'            => is_array($meta['tags'] ?? null) ? json_encode($meta['tags'], JSON_UNESCAPED_UNICODE) : ($existing['tags'] ?? '[]'),
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
        $now    = microtime(true);
        $redis  = $this->redis();
        $jobKey = $this->prefix . 'job:' . $jobId;

        $existing   = $redis->hGetAll($jobKey) ?: [];
        $createdAt  = isset($existing['created_at']) ? (float) $existing['created_at'] : $now;
        $startedAt  = isset($existing['started_at']) ? (float) $existing['started_at'] : $now;
        $waitTimeMs = sprintf('%.2f', max(0, ($startedAt - $createdAt) * 1000));

        $data = [
            'id'             => $jobId,
            'job_class'      => $existing['job_class'] ?? $jobClass,
            'queue'          => $existing['queue'] ?? $queue,
            'status'         => 'completed',
            'payload'        => $existing['payload'] ?? (is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            'finished_at'    => (string) $now,
            'started_at'     => (string) $startedAt,
            'created_at'     => (string) $createdAt,
            'duration_ms'    => sprintf('%.2f', $durationMs),
            'wait_time_ms'   => $waitTimeMs,
            'trace_id'       => $meta['trace_id'] ?? ($existing['trace_id'] ?? ''),
            'pid'            => (string) ($meta['pid'] ?? ($existing['pid'] ?? getmypid())),
            'hostname'       => (string) ($meta['hostname'] ?? ($existing['hostname'] ?? gethostname())),
            'memory_peak_mb' => sprintf('%.2f MB', (memory_get_peak_usage(true) / 1024 / 1024)),
            'tags'           => is_array($meta['tags'] ?? null) ? json_encode($meta['tags'], JSON_UNESCAPED_UNICODE) : ($existing['tags'] ?? '[]'),
        ];

        $redis->hMSet($jobKey, $data);
        $redis->expire($jobKey, 86400 * 3);

        $redis->hIncrBy($this->prefix . 'stats', 'completed', 1);
        $redis->zAdd($this->prefix . 'recent_jobs', (float) $now, $jobId);

        // Accumulate duration for avg calculation
        $redis->hIncrByFloat($this->prefix . 'stats', 'total_job_duration_ms', $durationMs);

        if (! empty($data['trace_id'])) {
            $redis->zAdd($this->prefix . 'trace:' . $data['trace_id'], (float) $now, $jobId);
            $redis->expire($this->prefix . 'trace:' . $data['trace_id'], 86400 * 3);
        }

        $this->trimRecentJobs($redis);
    }

    public function recordFailure(string $jobId, string $jobClass, string $queue, mixed $payload, Throwable $exception, float $durationMs, array $meta = []): void
    {
        $now    = microtime(true);
        $redis  = $this->redis();
        $jobKey = $this->prefix . 'job:' . $jobId;

        $existing   = $redis->hGetAll($jobKey) ?: [];
        $createdAt  = isset($existing['created_at']) ? (float) $existing['created_at'] : $now;
        $startedAt  = isset($existing['started_at']) ? (float) $existing['started_at'] : $now;
        $waitTimeMs = sprintf('%.2f', max(0, ($startedAt - $createdAt) * 1000));

        $data = [
            'id'               => $jobId,
            'job_class'        => $existing['job_class'] ?? $jobClass,
            'queue'            => $existing['queue'] ?? $queue,
            'status'           => 'failed',
            'payload'          => $existing['payload'] ?? (is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
            'finished_at'      => (string) $now,
            'started_at'       => (string) $startedAt,
            'created_at'       => (string) $createdAt,
            'duration_ms'      => sprintf('%.2f', $durationMs),
            'wait_time_ms'     => $waitTimeMs,
            'trace_id'         => $meta['trace_id'] ?? ($existing['trace_id'] ?? ''),
            'pid'              => (string) ($meta['pid'] ?? ($existing['pid'] ?? getmypid())),
            'hostname'         => (string) ($meta['hostname'] ?? ($existing['hostname'] ?? gethostname())),
            'memory_peak_mb'   => sprintf('%.2f MB', (memory_get_peak_usage(true) / 1024 / 1024)),
            'tags'             => is_array($meta['tags'] ?? null) ? json_encode($meta['tags'], JSON_UNESCAPED_UNICODE) : ($existing['tags'] ?? '[]'),
            'exception_message' => $exception->getMessage(),
            'exception_class'  => get_class($exception),
            'exception_trace'  => $exception->getTraceAsString(),
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
        $card  = (int) $redis->zCard($this->prefix . 'recent_jobs');
        if ($card > $limit) {
            $redis->zRemRangeByRank($this->prefix . 'recent_jobs', 0, $card - $limit - 1);
        }
    }

    protected function trimFailedJobs($redis): void
    {
        $limit = $this->failedLimit > 0 ? $this->failedLimit : 200;
        $card  = (int) $redis->zCard($this->prefix . 'failed_jobs');
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
        $failed    = (int) ($stats['failed'] ?? 0);
        $retried   = (int) ($stats['retried'] ?? 0);
        $totalRaw  = (int) ($stats['total'] ?? 0);
        $total     = max($totalRaw, $completed + $failed);

        $totalDurationMs = (float) ($stats['total_job_duration_ms'] ?? 0.0);
        $avgDurationMs   = $completed > 0 ? round($totalDurationMs / $completed, 2) : 0.0;

        return [
            'total'          => $total,
            'completed'      => $completed,
            'failed'         => $failed,
            'retried'        => $retried,
            'avg_duration_ms' => $avgDurationMs,
        ];
    }

    public function getRecentJobs(int $limit = 50): array
    {
        $redis  = $this->redis();
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
        $redis  = $this->redis();
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

    public function recordTraceSpan(string $traceId, array $spanData): void
    {
        $now    = microtime(true);
        $redis  = $this->redis();
        $spanId = $spanData['id'] ?? ('span_' . bin2hex(random_bytes(4)));

        $spanKey = $this->prefix . 'span:' . $spanId;
        $redis->hMSet($spanKey, $spanData);
        $redis->expire($spanKey, 86400 * 3);

        $redis->zAdd($this->prefix . 'trace:' . $traceId, (float) $now, 'span:' . $spanId);
        $redis->expire($this->prefix . 'trace:' . $traceId, 86400 * 3);

        // Also index slow queries separately
        $durationMs = (float) ($spanData['duration_ms'] ?? 0);
        if (($spanData['type'] ?? '') === 'db_query' && $durationMs >= $this->slowQueryThresholdMs) {
            $redis->zAdd($this->prefix . 'slow_queries', (float) $now, $spanId);
            $redis->expire($this->prefix . 'slow_queries', 86400 * 3);
            $this->trimSlowQueries($redis);
        }
    }

    public function getTraceJobs(string $traceId): array
    {
        $redis = $this->redis();
        $items = $redis->zRange($this->prefix . 'trace:' . $traceId, 0, -1) ?: [];

        $spans = [];
        foreach ($items as $item) {
            $item = (string) $item;

            if (str_starts_with($item, 'http:')) {
                // Root HTTP request span
                $httpId   = substr($item, 5);
                $httpData = $redis->hGetAll($this->prefix . 'http:' . $httpId);
                if ($httpData) {
                    $httpData['type']      = 'http';
                    $httpData['job_class'] = ($httpData['method'] ?? 'HTTP') . ' ' . ($httpData['path'] ?? $httpId);
                    array_unshift($spans, $httpData); // Always first
                }
            } elseif (str_starts_with($item, 'span:')) {
                // DB query span
                $spanId   = substr($item, 5);
                $spanData = $redis->hGetAll($this->prefix . 'span:' . $spanId);
                if ($spanData) {
                    $spans[] = $spanData;
                }
            } else {
                // Queue job
                $jobData = $this->getJob($item);
                if ($jobData) {
                    $jobData['type'] = 'job';
                    $spans[] = $jobData;
                }
            }
        }

        return $spans;
    }

    public function getJob(string $jobId): ?array
    {
        $redis   = $this->redis();
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

    public function deleteJobs(array $jobIds): int
    {
        $redis = $this->redis();
        $count = 0;
        foreach ($jobIds as $jobId) {
            $redis->del($this->prefix . 'job:' . $jobId);
            $redis->zRem($this->prefix . 'recent_jobs', $jobId);
            $redis->zRem($this->prefix . 'failed_jobs', $jobId);
            $count++;
        }

        return $count;
    }

    public function clearFailedJobs(): int
    {
        $redis  = $this->redis();
        $jobIds = $redis->zRange($this->prefix . 'failed_jobs', 0, -1) ?: [];
        $count  = count($jobIds);

        foreach ($jobIds as $jobId) {
            $redis->del($this->prefix . 'job:' . $jobId);
        }

        $redis->del($this->prefix . 'failed_jobs');

        return $count;
    }

    // =========================================================================
    // HTTP Request Methods
    // =========================================================================

    public function recordHttpRequest(string $traceId, array $data): void
    {
        $now      = microtime(true);
        $redis    = $this->redis();
        $httpKey  = $this->prefix . 'http:' . $traceId;

        $redis->hMSet($httpKey, $data);
        $redis->expire($httpKey, 86400 * 2);

        $redis->zAdd($this->prefix . 'http_requests', (float) $now, $traceId);

        // Register the HTTP request as the root span of the trace
        // so the Trace Explorer modal can show it at the top of the waterfall
        $redis->zAdd($this->prefix . 'trace:' . $traceId, (float) $now - 0.001, 'http:' . $traceId);
        $redis->expire($this->prefix . 'trace:' . $traceId, 86400 * 2);

        $statusCode = (int) ($data['status_code'] ?? 200);
        $redis->hIncrBy($this->prefix . 'http_stats', 'total', 1);

        if ($statusCode >= 500) {
            $redis->hIncrBy($this->prefix . 'http_stats', 'errors_5xx', 1);
        } elseif ($statusCode >= 400) {
            $redis->hIncrBy($this->prefix . 'http_stats', 'errors_4xx', 1);
        }

        $durationMs = (float) ($data['duration_ms'] ?? 0);
        $redis->hIncrByFloat($this->prefix . 'http_stats', 'total_duration_ms', $durationMs);

        if (($data['is_slow'] ?? '0') === '1') {
            $redis->hIncrBy($this->prefix . 'http_stats', 'slow_requests', 1);
        }

        $this->trimHttpRequests($redis);
    }

    public function getRecentHttpRequests(int $limit = 50, string $filter = 'all'): array
    {
        $redis      = $this->redis();
        $traceIds   = $redis->zRevRange($this->prefix . 'http_requests', 0, $limit * 3 - 1) ?: [];

        $requests = [];
        foreach ($traceIds as $traceId) {
            $data = $redis->hGetAll($this->prefix . 'http:' . $traceId);
            if (empty($data)) {
                continue;
            }

            if ($filter === 'slow' && ($data['is_slow'] ?? '0') !== '1') {
                continue;
            }

            if ($filter === 'errors' && (int) ($data['status_code'] ?? 200) < 400) {
                continue;
            }

            $requests[] = $data;

            if (count($requests) >= $limit) {
                break;
            }
        }

        return $requests;
    }

    public function getHttpStats(): array
    {
        $redis = $this->redis();
        $stats = $redis->hGetAll($this->prefix . 'http_stats') ?: [];

        $total           = (int) ($stats['total'] ?? 0);
        $errors5xx       = (int) ($stats['errors_5xx'] ?? 0);
        $errors4xx       = (int) ($stats['errors_4xx'] ?? 0);
        $slowRequests    = (int) ($stats['slow_requests'] ?? 0);
        $totalDurationMs = (float) ($stats['total_duration_ms'] ?? 0.0);
        $avgDurationMs   = $total > 0 ? round($totalDurationMs / $total, 2) : 0.0;

        return [
            'total'            => $total,
            'errors_5xx'       => $errors5xx,
            'errors_4xx'       => $errors4xx,
            'slow_requests'    => $slowRequests,
            'avg_duration_ms'  => $avgDurationMs,
            'error_rate'       => $total > 0 ? round(($errors5xx + $errors4xx) / $total * 100, 2) : 0.0,
        ];
    }

    protected function trimHttpRequests($redis): void
    {
        $limit = $this->httpLimit > 0 ? $this->httpLimit : 1000;
        $card  = (int) $redis->zCard($this->prefix . 'http_requests');
        if ($card > $limit) {
            $redis->zRemRangeByRank($this->prefix . 'http_requests', 0, $card - $limit - 1);
        }
    }

    // =========================================================================
    // Log Methods
    // =========================================================================

    public function recordLog(
        string $level,
        string $message,
        string $channel,
        string $traceId,
        array $context = [],
        array $extra = [],
        string $datetime = ''
    ): void {
        $now    = microtime(true);
        $redis  = $this->redis();
        $logId  = 'log_' . bin2hex(random_bytes(6));
        $logKey = $this->prefix . 'log:' . $logId;

        $data = [
            'id'         => $logId,
            'level'      => strtoupper($level),
            'message'    => mb_substr($message, 0, 2000),
            'channel'    => $channel,
            'trace_id'   => $traceId,
            'context'    => json_encode($context, JSON_UNESCAPED_UNICODE),
            'extra'      => json_encode($extra, JSON_UNESCAPED_UNICODE),
            'datetime'   => $datetime ?: date('Y-m-d H:i:s'),
            'created_at' => (string) $now,
        ];

        $redis->hMSet($logKey, $data);
        $redis->expire($logKey, 86400 * 2);

        $redis->zAdd($this->prefix . 'logs', (float) $now, $logId);

        // Level-specific index for filtering
        $redis->zAdd($this->prefix . 'logs:' . strtolower($level), (float) $now, $logId);
        $redis->expire($this->prefix . 'logs:' . strtolower($level), 86400 * 2);

        $this->trimLogs($redis);
    }

    public function getRecentLogs(int $limit = 50, string $minLevel = 'warning'): array
    {
        $redis = $this->redis();

        $levelPriority = [
            'debug'     => 0,
            'info'      => 1,
            'notice'    => 2,
            'warning'   => 3,
            'error'     => 4,
            'critical'  => 5,
            'alert'     => 6,
            'emergency' => 7,
        ];

        $minPriority = $levelPriority[strtolower($minLevel)] ?? 3;
        $logIds      = $redis->zRevRange($this->prefix . 'logs', 0, $limit * 4 - 1) ?: [];

        $logs = [];
        foreach ($logIds as $logId) {
            $data = $redis->hGetAll($this->prefix . 'log:' . $logId);
            if (empty($data)) {
                continue;
            }

            $dataLevel   = strtolower($data['level'] ?? 'info');
            $dataPriority = $levelPriority[$dataLevel] ?? 0;

            if ($dataPriority < $minPriority) {
                continue;
            }

            $logs[] = $data;

            if (count($logs) >= $limit) {
                break;
            }
        }

        return $logs;
    }

    public function clearLogs(): int
    {
        $redis  = $this->redis();
        $logIds = $redis->zRange($this->prefix . 'logs', 0, -1) ?: [];
        $count  = count($logIds);

        foreach ($logIds as $logId) {
            $redis->del($this->prefix . 'log:' . $logId);
        }

        $redis->del($this->prefix . 'logs');

        return $count;
    }

    protected function trimLogs($redis): void
    {
        $limit = $this->logLimit > 0 ? $this->logLimit : 500;
        $card  = (int) $redis->zCard($this->prefix . 'logs');
        if ($card > $limit) {
            $redis->zRemRangeByRank($this->prefix . 'logs', 0, $card - $limit - 1);
        }
    }

    // =========================================================================
    // Slow Query Methods
    // =========================================================================

    public function getSlowQueries(int $limit = 50): array
    {
        $redis   = $this->redis();
        $spanIds = $redis->zRevRange($this->prefix . 'slow_queries', 0, $limit - 1) ?: [];

        $queries = [];
        foreach ($spanIds as $spanId) {
            $data = $redis->hGetAll($this->prefix . 'span:' . $spanId);
            if (! empty($data)) {
                $queries[] = $data;
            }
        }

        return $queries;
    }

    protected function trimSlowQueries($redis): void
    {
        $limit = $this->slowQueryLimit > 0 ? $this->slowQueryLimit : 300;
        $card  = (int) $redis->zCard($this->prefix . 'slow_queries');
        if ($card > $limit) {
            $redis->zRemRangeByRank($this->prefix . 'slow_queries', 0, $card - $limit - 1);
        }
    }

    // =========================================================================
    // Overall Health / Overview
    // =========================================================================

    public function getHealth(): array
    {
        $jobStats  = $this->getStats();
        $httpStats = $this->getHttpStats();

        $redis = $this->redis();

        $recentJobCount  = (int) $redis->zCard($this->prefix . 'recent_jobs');
        $failedJobCount  = (int) $redis->zCard($this->prefix . 'failed_jobs');
        $httpCount       = (int) $redis->zCard($this->prefix . 'http_requests');
        $logCount        = (int) $redis->zCard($this->prefix . 'logs');
        $slowQueryCount  = (int) $redis->zCard($this->prefix . 'slow_queries');

        return [
            'jobs'        => $jobStats,
            'http'        => $httpStats,
            'counts' => [
                'recent_jobs'    => $recentJobCount,
                'failed_jobs'    => $failedJobCount,
                'http_requests'  => $httpCount,
                'logs'           => $logCount,
                'slow_queries'   => $slowQueryCount,
            ],
        ];
    }

    public function getPrometheusMetrics(): string
    {
        $health = $this->getHealth();

        $http   = $health['http'] ?? [];
        $jobs   = $health['jobs'] ?? [];
        $counts = $health['counts'] ?? [];

        $lines   = [];
        $lines[] = '# HELP chronos_http_requests_total Total number of HTTP requests tracked by Chronos.';
        $lines[] = '# TYPE chronos_http_requests_total counter';
        $lines[] = sprintf('chronos_http_requests_total %d', (int) ($http['total'] ?? 0));

        $lines[] = '# HELP chronos_http_errors_total Total number of HTTP 4xx and 5xx errors.';
        $lines[] = '# TYPE chronos_http_errors_total counter';
        $lines[] = sprintf('chronos_http_errors_total{code="4xx"} %d', (int) ($http['errors_4xx'] ?? 0));
        $lines[] = sprintf('chronos_http_errors_total{code="5xx"} %d', (int) ($http['errors_5xx'] ?? 0));

        $lines[] = '# HELP chronos_http_request_duration_ms_average Average duration of HTTP requests in ms.';
        $lines[] = '# TYPE chronos_http_request_duration_ms_average gauge';
        $lines[] = sprintf('chronos_http_request_duration_ms_average %.2f', (float) ($http['avg_duration_ms'] ?? 0));

        $lines[] = '# HELP chronos_jobs_processed_total Total queue jobs processed by status.';
        $lines[] = '# TYPE chronos_jobs_processed_total counter';
        $lines[] = sprintf('chronos_jobs_processed_total{status="completed"} %d', (int) ($jobs['completed'] ?? 0));
        $lines[] = sprintf('chronos_jobs_processed_total{status="failed"} %d', (int) ($jobs['failed'] ?? 0));
        $lines[] = sprintf('chronos_jobs_processed_total{status="retried"} %d', (int) ($jobs['retried'] ?? 0));

        $lines[] = '# HELP chronos_jobs_duration_ms_average Average job execution time in ms.';
        $lines[] = '# TYPE chronos_jobs_duration_ms_average gauge';
        $lines[] = sprintf('chronos_jobs_duration_ms_average %.2f', (float) ($jobs['avg_duration_ms'] ?? 0));

        $lines[] = '# HELP chronos_slow_queries_total Total database queries flagged as slow.';
        $lines[] = '# TYPE chronos_slow_queries_total counter';
        $lines[] = sprintf('chronos_slow_queries_total %d', (int) ($counts['slow_queries'] ?? 0));

        $lines[] = '# HELP chronos_logs_captured_total Total application logs captured in Redis.';
        $lines[] = '# TYPE chronos_logs_captured_total counter';
        $lines[] = sprintf('chronos_logs_captured_total %d', (int) ($counts['logs'] ?? 0));

        return implode("\n", $lines) . "\n";
    }
}
