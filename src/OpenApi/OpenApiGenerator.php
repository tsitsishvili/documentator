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
        $globalScheme = $this->defaultSecurityScheme();

        foreach ($endpoints as $endpoint) {
            $path = $this->normalizePath($endpoint->uri);
            $tag = $this->tagFor($endpoint);
            $tags[$tag] = true;

            foreach ($endpoint->verbs() as $verb) {
                $paths[$path][$verb] = $this->operation($endpoint, $tag, $globalScheme);
            }
        }

        return array_filter([
            'openapi' => '3.1.0',
            'info' => $this->info(),
            'servers' => config('documentator.servers', []),
            'security' => $globalScheme !== null ? [[$globalScheme => []]] : [],
            'tags' => array_map(fn (string $name) => ['name' => $name], array_keys($tags)),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => (object) config('documentator.security', []),
            ],
        ]);
    }

    /**
     * The security scheme applied to every operation by default (emitted as the
     * document's root `security`), or null when the API isn't globally
     * authenticated. config('documentator.authenticate') may be true (use the
     * "default" scheme) or the name of a scheme declared in `security`.
     */
    private function defaultSecurityScheme(): ?string
    {
        $value = config('documentator.authenticate', false);

        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return $value === true ? 'default' : (string) $value;
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
    private function operation(EndpointData $endpoint, string $tag, ?string $globalScheme = null): array
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
        } elseif ($globalScheme !== null) {
            // A global security requirement is in force; this operation isn't
            // authenticated, so opt it out with an empty requirement to keep it
            // public (overriding the document's root `security`).
            $operation['security'] = [];
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
        $schema = $this->schema($param);

        return array_filter([
            'name' => $param->name,
            'in' => $in,
            'required' => $required,
            'description' => $param->description,
            'schema' => $schema,
            'example' => $param->example ?? ($this->generatesExamples() ? SchemaSampler::sample($schema) : null),
        ], fn ($value) => $value !== null);
    }

    private function generatesExamples(): bool
    {
        return (bool) config('documentator.generate_examples', true);
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

        $content = ['schema' => $schema];

        // A whole-body example so the playground starts with a fillable payload.
        if ($this->generatesExamples() && $mediaType === 'application/json') {
            $content['example'] = SchemaSampler::sample($schema);
        }

        return [
            'required' => $required !== [],
            'content' => [$mediaType => $content],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responses(EndpointData $endpoint): array
    {
        $responses = [];

        // Guarantee a success response even when only error responses were
        // inferred, so an endpoint never documents 4xx with no 2xx. Seeded first
        // so it stays the leading entry. The status follows the verb convention
        // (POST -> 201, DELETE -> 204) when nothing carried a body.
        if (! $this->hasSuccessResponse($endpoint)) {
            [$status, $description] = $this->fallbackSuccess($endpoint);
            $responses[$status] = ['description' => $description];
        }

        foreach ($endpoint->responses as $response) {
            $responses[(string) $response->status] = $this->response($response);
        }

        return $responses;
    }

    /**
     * The status + description for the guaranteed success response of an endpoint
     * that produced no 2xx of its own: a verb convention (POST -> 201 Created,
     * DELETE -> 204 No Content) unless status inference is disabled.
     *
     * @return array{0: string, 1: string}
     */
    private function fallbackSuccess(EndpointData $endpoint): array
    {
        if (config('documentator.infer_status_codes', true)) {
            $verbs = $endpoint->verbs();

            if (in_array('post', $verbs, true)) {
                return ['201', 'Created'];
            }
            if (in_array('delete', $verbs, true)) {
                return ['204', 'No content'];
            }
        }

        return ['200', 'Successful response'];
    }

    private function hasSuccessResponse(EndpointData $endpoint): bool
    {
        foreach ($endpoint->responses as $response) {
            if ($response->status >= 200 && $response->status < 300) {
                return true;
            }
        }

        return false;
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
