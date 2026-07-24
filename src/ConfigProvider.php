<?php

declare(strict_types=1);

namespace Chronos;

use Chronos\Controller\ApiController;
use Chronos\Controller\DashboardController;
use Chronos\Listener\JobEventListener;
use Chronos\Listener\RegisterRoutesListener;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [],
            'listeners' => [
                JobEventListener::class,
                RegisterRoutesListener::class,
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
