<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

use Illuminate\Support\Str;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ParameterData;
use Tsitsishvili\Documentator\Data\ResponseData;
use Tsitsishvili\Documentator\Extraction\Support\RuleParser;

/**
 * Renders a list of EndpointData into an OpenAPI 3.1 document (a plain array
 * ready to be JSON-encoded and handed to Scalar).
 */
final class OpenApiGenerator
{
    /**
     * @param  array<int, EndpointData>  $endpoints
     * @return array<string, mixed>
     */
    public function generate(array $endpoints): array
    {
        $paths = [];
        $tags = [];

        foreach ($endpoints as $endpoint) {
            $path = $this->normalizePath($endpoint->uri);
            $tag = $this->tagFor($endpoint);
            $tags[$tag] = true;

            foreach ($endpoint->verbs() as $verb) {
                $paths[$path][$verb] = $this->operation($endpoint, $tag);
            }
        }

        return array_filter([
            'openapi' => '3.1.0',
            'info' => $this->info(),
            'servers' => config('documentator.servers', []),
            'tags' => array_map(fn (string $name) => ['name' => $name], array_keys($tags)),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => (object) config('documentator.security', []),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function info(): array
    {
        return array_filter([
            'title' => config('documentator.title'),
            'version' => config('documentator.version'),
            'description' => config('documentator.description'),
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(EndpointData $endpoint, string $tag): array
    {
        $operation = array_filter([
            'operationId' => $endpoint->operationId(),
            'tags' => [$tag],
            'summary' => $endpoint->summary,
            'description' => $endpoint->description,
            'parameters' => $this->parameters($endpoint),
            'requestBody' => $this->requestBody($endpoint),
            'responses' => $this->responses($endpoint),
            'deprecated' => $endpoint->deprecated ?: null,
        ], fn ($value) => $value !== null && $value !== []);

        if ($endpoint->authenticated) {
            $operation['security'] = [[$endpoint->securityScheme ?? 'default' => []]];
        }

        return $operation;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parameters(EndpointData $endpoint): array
    {
        $parameters = [];

        foreach ($endpoint->pathParameters as $param) {
            $parameters[] = $this->parameter($param, 'path', true);
        }

        foreach ($endpoint->queryParameters as $param) {
            $parameters[] = $this->parameter($param, 'query', $param->required);
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    private function parameter(ParameterData $param, string $in, bool $required): array
    {
        return array_filter([
            'name' => $param->name,
            'in' => $in,
            'required' => $required,
            'description' => $param->description,
            'schema' => $this->schema($param),
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(EndpointData $endpoint): ?array
    {
        if ($endpoint->bodyParameters === []) {
            return null;
        }

        $properties = [];
        $required = [];

        foreach ($endpoint->bodyParameters as $param) {
            $properties[$param->name] = $this->schema($param);

            if ($param->required) {
                $required[] = $param->name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        $mediaType = RuleParser::hasUpload(array_values($endpoint->bodyParameters))
            ? 'multipart/form-data'
            : 'application/json';

        return [
            'required' => $required !== [],
            'content' => [$mediaType => ['schema' => $schema]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responses(EndpointData $endpoint): array
    {
        if ($endpoint->responses === []) {
            return ['200' => ['description' => 'Successful response']];
        }

        $responses = [];

        foreach ($endpoint->responses as $response) {
            $responses[(string) $response->status] = $this->response($response);
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function response(ResponseData $response): array
    {
        $description = $response->description
            ?? ($response->resource !== null ? class_basename($response->resource) : 'Response');

        $body = ['description' => $description];

        if ($response->example !== null) {
            $body['content'] = ['application/json' => ['example' => $response->example]];
        } elseif ($response->schema !== null) {
            $body['content'] = ['application/json' => ['schema' => $response->schema]];
        } elseif ($response->resource !== null) {
            $body['content'] = ['application/json' => ['schema' => ['type' => 'object']]];
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(ParameterData $param): array
    {
        if ($param->schema !== null) {
            $schema = $param->schema;
            if ($param->description !== null && ! isset($schema['description'])) {
                $schema['description'] = $param->description;
            }

            return $schema;
        }

        $schema = ['type' => $param->type];

        if ($param->type === 'array') {
            $schema['items'] = ['type' => 'string'];
        }

        if ($param->description !== null) {
            $schema['description'] = $param->description;
        }

        if ($param->example !== null) {
            $schema['example'] = $param->example;
        }

        return $schema;
    }

    private function normalizePath(string $uri): string
    {
        // Laravel optional params ({id?}) aren't valid OpenAPI path templates.
        return '/'.ltrim(str_replace('?}', '}', $uri), '/');
    }

    private function tagFor(EndpointData $endpoint): string
    {
        if ($endpoint->group !== null) {
            return $endpoint->group;
        }

        if ($endpoint->controller !== null) {
            return Str::headline(Str::replaceLast('Controller', '', class_basename($endpoint->controller)));
        }

        return 'Endpoints';
    }
}
