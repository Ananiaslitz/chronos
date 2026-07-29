<?php

declare(strict_types=1);

namespace Chronos\Controller;

use Chronos\Storage\RedisStorage;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Container\ContainerInterface;

#[Controller(prefix: '/chronos/api')]
class ApiController
{
    public function __construct(
        protected RedisStorage $storage,
        protected ContainerInterface $container
    ) {}

    // =========================================================================
    // Overview / Health
    // =========================================================================

    #[GetMapping(path: 'health')]
    public function health(ResponseInterface $response)
    {
        return $response->json([
            'status' => 'success',
            'data'   => $this->storage->getHealth(),
        ]);
    }

    // =========================================================================
    // Queue Job Endpoints
    // =========================================================================

    #[GetMapping(path: 'stats')]
    public function stats(ResponseInterface $response)
    {
        return $response->json([
            'status' => 'success',
            'data'   => $this->storage->getStats(),
        ]);
    }

    #[GetMapping(path: 'jobs')]
    public function jobs(RequestInterface $request, ResponseInterface $response)
    {
        $type  = $request->input('type', 'recent');
        $limit = (int) $request->input('limit', 50);

        $jobs = $type === 'failed'
            ? $this->storage->getFailedJobs($limit)
            : $this->storage->getRecentJobs($limit);

        return $response->json([
            'status' => 'success',
            'type'   => $type,
            'count'  => count($jobs),
            'data'   => $jobs,
        ]);
    }

    #[GetMapping(path: 'jobs/{id}')]
    public function show(string $id, ResponseInterface $response)
    {
        $job = $this->storage->getJob($id);

        if (! $job) {
            return $response->json([
                'status'  => 'error',
                'message' => 'Job not found',
            ])->withStatus(404);
        }

        return $response->json([
            'status' => 'success',
            'data'   => $job,
        ]);
    }

    #[GetMapping(path: 'trace/{traceId}')]
    public function trace(string $traceId, ResponseInterface $response)
    {
        $jobs = $this->storage->getTraceJobs($traceId);

        return $response->json([
            'status'   => 'success',
            'trace_id' => $traceId,
            'count'    => count($jobs),
            'data'     => $jobs,
        ]);
    }

    #[PostMapping(path: 'jobs/{id}/retry')]
    public function retry(string $id, ResponseInterface $response)
    {
        $jobData = $this->storage->getJob($id);

        if (! $jobData) {
            return $response->json([
                'status'  => 'error',
                'message' => 'Job not found for retry',
            ])->withStatus(404);
        }

        try {
            $queueName = $jobData['queue'] ?? 'default';
            $jobClass  = $jobData['job_class'] ?? null;
            $payload   = json_decode($jobData['payload'] ?? '[]', true);

            if ($this->container->has(DriverFactory::class)) {
                $driverFactory = $this->container->get(DriverFactory::class);
                $driver        = $driverFactory->get($queueName);

                if ($jobClass && class_exists($jobClass)) {
                    $jobInstance = new $jobClass(...array_values($payload));
                    $driver->push($jobInstance);
                }
            }

            $this->storage->recordRetry($id);

            return $response->json([
                'status'  => 'success',
                'message' => 'Job successfully re-queued for retry',
            ]);
        } catch (\Throwable $e) {
            return $response->json([
                'status'  => 'error',
                'message' => 'Failed to retry job: ' . $e->getMessage(),
            ])->withStatus(500);
        }
    }

    #[PostMapping(path: 'jobs/batch-retry')]
    public function batchRetry(RequestInterface $request, ResponseInterface $response)
    {
        $ids     = (array) $request->input('ids', []);
        $retried = 0;

        foreach ($ids as $id) {
            $jobData = $this->storage->getJob((string) $id);
            if (! $jobData) {
                continue;
            }

            try {
                $queueName = $jobData['queue'] ?? 'default';
                $jobClass  = $jobData['job_class'] ?? null;
                $payload   = json_decode($jobData['payload'] ?? '[]', true);

                if ($this->container->has(DriverFactory::class)) {
                    $driverFactory = $this->container->get(DriverFactory::class);
                    $driver        = $driverFactory->get($queueName);

                    if ($jobClass && class_exists($jobClass)) {
                        $jobInstance = new $jobClass(...array_values($payload));
                        $driver->push($jobInstance);
                    }
                }

                $this->storage->recordRetry((string) $id);
                $retried++;
            } catch (\Throwable $e) {
                // Ignore individual retry error
            }
        }

        return $response->json([
            'status'        => 'success',
            'message'       => sprintf('Re-queued %d jobs for retry', $retried),
            'retried_count' => $retried,
        ]);
    }

    #[PostMapping(path: 'jobs/batch-delete')]
    public function batchDelete(RequestInterface $request, ResponseInterface $response)
    {
        $ids   = (array) $request->input('ids', []);
        $count = $this->storage->deleteJobs($ids);

        return $response->json([
            'status'        => 'success',
            'message'       => sprintf('Deleted %d jobs', $count),
            'deleted_count' => $count,
        ]);
    }

    #[DeleteMapping(path: 'jobs/{id}')]
    public function delete(string $id, ResponseInterface $response)
    {
        $this->storage->deleteJob($id);

        return $response->json([
            'status'  => 'success',
            'message' => 'Job deleted successfully',
        ]);
    }

    #[DeleteMapping(path: 'jobs/failed/all')]
    public function clearFailed(ResponseInterface $response)
    {
        $count = $this->storage->clearFailedJobs();

        return $response->json([
            'status'        => 'success',
            'message'       => sprintf('Cleared %d failed jobs', $count),
            'cleared_count' => $count,
        ]);
    }

    // =========================================================================
    // HTTP Request Endpoints
    // =========================================================================

    #[GetMapping(path: 'http')]
    public function httpRequests(RequestInterface $request, ResponseInterface $response)
    {
        $filter = $request->input('filter', 'all'); // all | slow | errors
        $limit  = (int) $request->input('limit', 50);

        $requests = $this->storage->getRecentHttpRequests($limit, $filter);

        return $response->json([
            'status' => 'success',
            'filter' => $filter,
            'count'  => count($requests),
            'data'   => $requests,
        ]);
    }

    #[GetMapping(path: 'http/stats')]
    public function httpStats(ResponseInterface $response)
    {
        return $response->json([
            'status' => 'success',
            'data'   => $this->storage->getHttpStats(),
        ]);
    }

    // =========================================================================
    // Log Endpoints
    // =========================================================================

    #[GetMapping(path: 'logs')]
    public function logs(RequestInterface $request, ResponseInterface $response)
    {
        $minLevel = $request->input('min_level', 'warning');
        $limit    = (int) $request->input('limit', 50);

        $logs = $this->storage->getRecentLogs($limit, $minLevel);

        return $response->json([
            'status'    => 'success',
            'min_level' => $minLevel,
            'count'     => count($logs),
            'data'      => $logs,
        ]);
    }

    #[DeleteMapping(path: 'logs')]
    public function clearLogs(ResponseInterface $response)
    {
        $count = $this->storage->clearLogs();

        return $response->json([
            'status'        => 'success',
            'message'       => sprintf('Cleared %d log entries', $count),
            'cleared_count' => $count,
        ]);
    }

    // =========================================================================
    // Slow Query Endpoints
    // =========================================================================

    #[GetMapping(path: 'slow-queries')]
    public function slowQueries(RequestInterface $request, ResponseInterface $response)
    {
        $limit   = (int) $request->input('limit', 50);
        $queries = $this->storage->getSlowQueries($limit);

        return $response->json([
            'status' => 'success',
            'count'  => count($queries),
            'data'   => $queries,
        ]);
    }

    // =========================================================================
    // Prometheus Metrics Exporter
    // =========================================================================

    #[GetMapping(path: '/chronos/metrics')]
    public function metrics(ResponseInterface $response)
    {
        return $response
            ->withHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->raw($this->storage->getPrometheusMetrics());
    }
}
