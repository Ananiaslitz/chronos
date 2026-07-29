<?php

declare(strict_types=1);

namespace Chronos\Alerting;

use Chronos\Storage\RedisStorage;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Guzzle\ClientFactory;
use Throwable;

class AlertManager
{
    protected bool $enabled;
    protected string $webhookUrl;
    protected float $httpErrorRateThreshold;
    protected int $jobFailureThreshold;

    public function __construct(
        protected RedisStorage $storage,
        protected ConfigInterface $config,
        protected ClientFactory $clientFactory
    ) {
        $this->enabled                = (bool) $this->config->get('chronos.alerts.enabled', false);
        $this->webhookUrl             = (string) $this->config->get('chronos.alerts.webhook_url', '');
        $this->httpErrorRateThreshold = (float) $this->config->get('chronos.alerts.http_error_rate_threshold', 5.0);
        $this->jobFailureThreshold     = (int) $this->config->get('chronos.alerts.job_failure_threshold', 10);
    }

    public function isEnabled(): bool
    {
        return $this->enabled && ! empty($this->webhookUrl);
    }

    public function sendAlert(string $title, string $message, string $severity = 'warning', array $meta = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $client = $this->clientFactory->create();

            $payload = [
                'text'        => sprintf('*[Chronos Alert - %s]* %s: %s', strtoupper($severity), $title, $message),
                'title'       => $title,
                'message'     => $message,
                'severity'    => $severity,
                'environment' => (string) $this->config->get('app_env', 'production'),
                'timestamp'   => date('Y-m-d H:i:s'),
                'metadata'    => $meta,
            ];

            $client->post($this->webhookUrl, [
                'json'    => $payload,
                'timeout' => 5.0,
            ]);
        } catch (Throwable $e) {
            // Silently ignore alert delivery failure
        }
    }

    public function evaluateHealthAlerts(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $health    = $this->storage->getHealth();
        $http      = $health['http'] ?? [];
        $jobs      = $health['jobs'] ?? [];

        $errorRate = (float) ($http['error_rate'] ?? 0);
        if ($errorRate >= $this->httpErrorRateThreshold && ($http['total'] ?? 0) > 10) {
            $this->sendAlert(
                'High HTTP Error Rate Spiking',
                sprintf('HTTP error rate reached %.1f%% (threshold: %.1f%%)', $errorRate, $this->httpErrorRateThreshold),
                'error',
                $http
            );
        }

        $failedJobs = (int) ($jobs['failed'] ?? 0);
        if ($failedJobs >= $this->jobFailureThreshold) {
            $this->sendAlert(
                'Queue Job Failure Threshold Exceeded',
                sprintf('%d queue jobs have failed (threshold: %d)', $failedJobs, $this->jobFailureThreshold),
                'critical',
                $jobs
            );
        }
    }
}
