<?php

declare(strict_types=1);

namespace Chronos\Listener;

use Chronos\Controller\ApiController;
use Chronos\Controller\DashboardController;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\HttpServer\Router\Router;

use function Hyperf\Config\config;

#[Listener]
class RegisterRoutesListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            BootApplication::class,
            BeforeMainServerStart::class,
        ];
    }

    public function process(object $event): void
    {
        $prefix = function_exists('Hyperf\Config\config')
            ? config('chronos.route_prefix', '/chronos')
            : '/chronos';

        Router::addServer('http', function () use ($prefix) {
            Router::addGroup($prefix, function () {
                Router::get('', [DashboardController::class, 'index']);
                Router::get('/api/stats', [ApiController::class, 'stats']);
                Router::get('/api/jobs', [ApiController::class, 'jobs']);
                Router::get('/api/jobs/{id}', [ApiController::class, 'show']);
                Router::post('/api/jobs/{id}/retry', [ApiController::class, 'retry']);
                Router::delete('/api/jobs/{id}', [ApiController::class, 'delete']);
                Router::delete('/api/jobs/failed/all', [ApiController::class, 'clearFailed']);
            });
        });
    }
}
