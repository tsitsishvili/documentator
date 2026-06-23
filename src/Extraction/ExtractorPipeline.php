<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction;

use Closure;
use Illuminate\Routing\Route;
use Tsitsishvili\Documentator\Data\EndpointData;
use ReflectionMethod;
use Throwable;

/**
 * Runs a route through the ordered list of extraction strategies, producing a
 * fully described EndpointData. Strategy order matters: inference strategies
 * run first and the attribute strategy runs last so explicit docs win.
 */
final class ExtractorPipeline
{
    /**
     * @param  array<int, ExtractionStrategy>  $strategies
     */
    public function __construct(private readonly array $strategies) {}

    public function process(Route $route): EndpointData
    {
        $endpoint = new EndpointData;
        $method = $this->reflectController($route);

        foreach ($this->strategies as $strategy) {
            $endpoint = $strategy($endpoint, $route, $method);
        }

        return $endpoint;
    }

    /**
     * Resolve the controller method backing the route, if any.
     */
    private function reflectController(Route $route): ?ReflectionMethod
    {
        if ($route->getAction('uses') instanceof Closure) {
            return null;
        }

        $controller = $route->getAction('controller');

        if (! is_string($controller)) {
            return null;
        }

        [$class, $method] = str_contains($controller, '@')
            ? explode('@', $controller, 2)
            : [$controller, '__invoke'];

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        try {
            return new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return null;
        }
    }
}
