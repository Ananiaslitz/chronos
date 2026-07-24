<?php

declare(strict_types=1);

namespace Chronos\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Contract\ResponseInterface;

#[Controller(prefix: '/chronos')]
class DashboardController
{
    #[GetMapping(path: '')]
    public function index(ResponseInterface $response)
    {
        $viewPath = __DIR__ . '/../View/dashboard.html';

        if (! file_exists($viewPath)) {
            return $response->raw('Chronos dashboard view file not found')->withStatus(404);
        }

        $html = file_get_contents($viewPath);

        return $response->raw($html)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
