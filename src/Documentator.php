<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator;

use Closure;
use Illuminate\Http\Request;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Extraction\ExtractorPipeline;
use Tsitsishvili\Documentator\Extraction\RouteCollector;
use Tsitsishvili\Documentator\OpenApi\OpenApiGenerator;

/**
 * High-level entry point. Collects documentable routes, runs each through the
 * extraction pipeline, and renders the result as an OpenAPI document.
 */
final class Documentator
{
    /**
     * Authorization gate for the docs routes. Null means open (subject only to
     * EnsureDocsEnabled); register a callback to restrict who may view them.
     *
     * @var (Closure(Request): bool)|null
     */
    private static ?Closure $authUsing = null;

    public function __construct(
        private readonly RouteCollector $collector,
        private readonly ExtractorPipeline $pipeline,
        private readonly OpenApiGenerator $generator,
    ) {}

    /**
     * Restrict who may view the docs. The callback receives the request and
     * returns true to allow access. Register it from a service provider's
     * boot() method, after the request's auth middleware has resolved the user.
     *
     * @param  Closure(Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        self::$authUsing = $callback;
    }

    /**
     * Whether the current request is authorized to view the docs. Open unless a
     * callback has been registered via auth().
     */
    public static function check(Request $request): bool
    {
        return (self::$authUsing ?? static fn (): bool => true)($request);
    }

    /**
     * @return array<int, EndpointData>
     */
    public function endpoints(): array
    {
        $endpoints = [];

        foreach ($this->collector->collect() as $route) {
            $endpoint = $this->pipeline->process($route);

            if (! $endpoint->hidden) {
                $endpoints[] = $endpoint;
            }
        }

        return $endpoints;
    }

    /**
     * @return array<string, mixed>
     */
    public function toOpenApi(): array
    {
        return $this->generator->generate($this->endpoints());
    }
}
