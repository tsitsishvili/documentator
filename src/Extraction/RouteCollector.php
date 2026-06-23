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
     */
    public function __construct(
        private readonly Router $router,
        private readonly array $match,
        private readonly array $exclude,
    ) {}

    /**
     * @return array<int, Route>
     */
    public function collect(): array
    {
        $routes = [];

        foreach ($this->router->getRoutes() as $route) {
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

        return true;
    }
}
