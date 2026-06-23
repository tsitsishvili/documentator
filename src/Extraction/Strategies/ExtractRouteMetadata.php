<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ParameterData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use ReflectionMethod;

/**
 * Seeds the endpoint from the route itself: verbs, URI, name, controller, path
 * parameters, an auth guess from middleware, and a humanised summary.
 */
final class ExtractRouteMetadata implements ExtractionStrategy
{
    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        $endpoint->httpMethods = $route->methods();
        $endpoint->uri = $route->uri();
        $endpoint->routeName = $route->getName();

        if ($method !== null) {
            $endpoint->controller = $method->class;
            $endpoint->method = $method->name;
            $endpoint->summary = Str::headline($method->name);
        }

        foreach ($this->pathParameters($route->uri()) as $name => $required) {
            $endpoint->pathParameters[$name] = new ParameterData(
                name: $name,
                type: 'string',
                required: $required,
            );
        }

        if ($this->hasAuthMiddleware($route)) {
            $endpoint->authenticated = true;
            $endpoint->securityScheme = 'default';
        }

        return $endpoint;
    }

    /**
     * @return array<string, bool> parameter name => required
     */
    private function pathParameters(string $uri): array
    {
        preg_match_all('/\{(\w+?)(\?)?\}/', $uri, $matches, PREG_SET_ORDER);

        $params = [];

        foreach ($matches as $match) {
            $params[$match[1]] = ! isset($match[2]);
        }

        return $params;
    }

    private function hasAuthMiddleware(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && Str::startsWith($middleware, ['auth', 'auth:'])) {
                return true;
            }
        }

        return false;
    }
}
