<?php

declare(strict_types=1);

namespace Chronos;

use Chronos\Controller\ApiController;
use Chronos\Controller\DashboardController;
use Chronos\Listener\JobEventListener;
use function Hyperf\Config\config;
use function Hyperf\Support\config as support_config;

class ConfigProvider
{
    public function __invoke(): array
    {
        // Register HTTP Routes dynamically if Router class exists
        if (class_exists(Router::class)) {
            $prefix = function_exists('Hyperf\Config\config') 
                ? config('chronos.route_prefix', '/chronos') 
                : (function_exists('config') ? \config('chronos.route_prefix', '/chronos') : '/chronos');

            Router::addGroup($prefix, function () {
                Router::get('', [DashboardController::class, 'index']);
                Router::get('/api/stats', [ApiController::class, 'stats']);
                Router::get('/api/jobs', [ApiController::class, 'jobs']);
                Router::get('/api/jobs/{id}', [ApiController::class, 'show']);
                Router::post('/api/jobs/{id}/retry', [ApiController::class, 'retry']);
                Router::delete('/api/jobs/{id}', [ApiController::class, 'delete']);
                Router::delete('/api/jobs/failed/all', [ApiController::class, 'clearFailed']);
            });
        }

        return [
            'dependencies' => [],
            'listeners' => [
                JobEventListener::class,
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file for Chronos.',
                    'source' => __DIR__ . '/../publish/chronos.php',
                    'destination' => BASE_PATH . '/config/autoload/chronos.php',
                ],
            ],
        ];
    }
}
