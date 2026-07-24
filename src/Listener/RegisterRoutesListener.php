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
            $routeCollector = Router::getRouteCollector('http');
            $routeCollector->addGroup($prefix, function ($route) {
                $route->get('', [DashboardController::class, 'index']);
                $route->get('/api/stats', [ApiController::class, 'stats']);
                $route->get('/api/jobs', [ApiController::class, 'jobs']);
                $route->get('/api/jobs/{id}', [ApiController::class, 'show']);
                $route->post('/api/jobs/{id}/retry', [ApiController::class, 'retry']);
                $route->delete('/api/jobs/{id}', [ApiController::class, 'delete']);
                $route->delete('/api/jobs/failed/all', [ApiController::class, 'clearFailed']);
            });
        });
    }
}
