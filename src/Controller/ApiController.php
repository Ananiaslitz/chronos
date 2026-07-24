<?php

declare(strict_types=1);

namespace Chronos\Controller;

use Chronos\Storage\RedisStorage;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Container\ContainerInterface;

class ApiController
{
    public function __construct(
        protected RedisStorage $storage,
        protected ContainerInterface $container
    ) {}

    public function stats(ResponseInterface $response)
    {
        return $response->json([
            'status' => 'success',
            'data' => $this->storage->getStats(),
        ]);
    }

    public function jobs(RequestInterface $request, ResponseInterface $response)
    {
        $type = $request->input('type', 'recent');
        $limit = (int) $request->input('limit', 50);

        $jobs = $type === 'failed' 
            ? $this->storage->getFailedJobs($limit) 
            : $this->storage->getRecentJobs($limit);

        return $response->json([
            'status' => 'success',
            'type' => $type,
            'count' => count($jobs),
            'data' => $jobs,
        ]);
    }

    public function show(string $id, ResponseInterface $response)
    {
        $job = $this->storage->getJob($id);

        if (! $job) {
            return $response->json([
                'status' => 'error',
                'message' => 'Job not found',
            ])->withStatus(404);
        }

        return $response->json([
            'status' => 'success',
            'data' => $job,
        ]);
    }

    public function retry(string $id, ResponseInterface $response)
    {
        $jobData = $this->storage->getJob($id);

        if (! $jobData) {
            return $response->json([
                'status' => 'error',
                'message' => 'Job not found for retry',
            ])->withStatus(404);
        }

        try {
            $queueName = $jobData['queue'] ?? 'default';
            $jobClass = $jobData['job_class'] ?? null;
            $payload = json_decode($jobData['payload'] ?? '[]', true);

            if ($this->container->has(DriverFactory::class)) {
                $driverFactory = $this->container->get(DriverFactory::class);
                $driver = $driverFactory->get($queueName);

                if ($jobClass && class_exists($jobClass)) {
                    // Re-instantiate job instance if possible
                    $jobInstance = new $jobClass(...array_values($payload));
                    $driver->push($jobInstance);
                }
            }

            $this->storage->recordRetry($id);

            return $response->json([
                'status' => 'success',
                'message' => 'Job successfully re-queued for retry',
            ]);
        } catch (\Throwable $e) {
            return $response->json([
                'status' => 'error',
                'message' => 'Failed to retry job: ' . $e->getMessage(),
            ])->withStatus(500);
        }
    }

    public function delete(string $id, ResponseInterface $response)
    {
        $this->storage->deleteJob($id);

        return $response->json([
            'status' => 'success',
            'message' => 'Job deleted successfully',
        ]);
    }

    public function clearFailed(ResponseInterface $response)
    {
        $count = $this->storage->clearFailedJobs();

        return $response->json([
            'status' => 'success',
            'message' => sprintf('Cleared %d failed jobs', $count),
            'cleared_count' => $count,
        ]);
    }
}
