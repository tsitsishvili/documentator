<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator;

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
    public function __construct(
        private readonly RouteCollector $collector,
        private readonly ExtractorPipeline $pipeline,
        private readonly OpenApiGenerator $generator,
    ) {}

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
