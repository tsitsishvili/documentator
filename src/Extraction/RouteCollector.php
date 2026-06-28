<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * Pulls the host application's registered routes and filters them down to the
 * set that should appear in the docs, using the config match/exclude patterns.
 */
final class RouteCollector
{
    /**
     * @param  array<int, string>  $match
     * @param  array<int, string>  $exclude
     * @param  array<int, string>  $excludeMiddleware
     */
    public function __construct(
        private readonly Router $router,
        private readonly array $match,
        private readonly array $exclude,
        private readonly array $excludeMiddleware = [],
    ) {}

    /**
     * @return array<int, Route>
     */
    public function collect(): array
    {
        $routes = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if ($this->shouldDocument($route)) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    private function shouldDocument(Route $route): bool
    {
        $uri = $route->uri();
        $name = $route->getName();

        if (! Str::is($this->match, $uri)) {
            return false;
        }

        foreach ($this->exclude as $pattern) {
            if (Str::is($pattern, $uri) || ($name !== null && Str::is($pattern, $name))) {
                return false;
            }
        }

        foreach ($this->excludeMiddleware as $pattern) {
            if ($this->usesMiddleware($route, $pattern)) {
                return false;
            }
        }

        return true;
    }

    private function usesMiddleware(Route $route, string $pattern): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (Str::is($pattern, $middleware) || Str::is($pattern, Str::before($middleware, ':'))) {
                return true;
            }
        }

        return false;
    }
}
