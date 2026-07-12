<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction;

use Closure;
use Illuminate\Routing\Route;
use ReflectionMethod;
use Throwable;
use Tsitsishvili\Documentator\Data\EndpointData;

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
            $before = $this->facets($endpoint);
            $endpoint = $strategy($endpoint, $route, $method);
            $this->recordChanges($endpoint, $before, $this->facets($endpoint), class_basename($strategy));
        }

        return $endpoint;
    }

    /**
     * Reduce mutable endpoint data to stable, user-facing documentation facets
     * so provenance can be captured without coupling strategies to a tracer.
     *
     * @return array<string, string>
     */
    private function facets(EndpointData $endpoint): array
    {
        $facets = [];
        $scalars = [
            'route.methods' => $endpoint->httpMethods,
            'route.uri' => $endpoint->uri,
            'route.name' => $endpoint->routeName,
            'action.controller' => $endpoint->controller,
            'action.method' => $endpoint->method,
            'action.introspectable' => $endpoint->introspectable,
            'metadata.summary' => $endpoint->summary,
            'metadata.description' => $endpoint->description,
            'metadata.operation_id' => $endpoint->operationId,
            'metadata.group' => $endpoint->group,
            'metadata.group_version' => $endpoint->groupVersion,
            'metadata.group_description' => $endpoint->groupDescription,
            'request.media_type' => $endpoint->requestMediaType,
            'security.authenticated' => $endpoint->authenticated,
            'security.scheme' => $endpoint->securityScheme,
            'security.scopes' => $endpoint->securityScopes,
            'visibility.hidden' => $endpoint->hidden,
            'visibility.deprecated' => $endpoint->deprecated,
        ];

        foreach ($scalars as $name => $value) {
            if ($value !== null && $value !== '' && $value !== [] && $value !== false) {
                $facets[$name] = $this->fingerprint($value);
            }
        }

        foreach ([
            'path' => $endpoint->pathParameters,
            'query' => $endpoint->queryParameters,
            'header' => $endpoint->headerParameters,
            'cookie' => $endpoint->cookieParameters,
            'body' => $endpoint->bodyParameters,
        ] as $location => $parameters) {
            foreach ($parameters as $name => $parameter) {
                $facets["parameter.{$location}.{$name}"] = $this->fingerprint($parameter);
            }
        }

        foreach ($endpoint->responses as $status => $response) {
            $facets["response.{$status}"] = $this->fingerprint($response);
        }

        foreach ($endpoint->servers as $index => $server) {
            $facets["server.{$index}"] = $this->fingerprint($server);
        }

        ksort($facets);

        return $facets;
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     */
    private function recordChanges(EndpointData $endpoint, array $before, array $after, string $strategy): void
    {
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
            if (($before[$field] ?? null) === ($after[$field] ?? null)) {
                continue;
            }

            $effect = ! array_key_exists($field, $before)
                ? 'inferred'
                : (! array_key_exists($field, $after) ? 'removed' : 'overrode');

            $endpoint->provenance[] = compact('field', 'strategy', 'effect');
        }
    }

    private function fingerprint(mixed $value): string
    {
        return serialize($value);
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
