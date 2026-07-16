<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

use Illuminate\Support\Str;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ParameterData;
use Tsitsishvili\Documentator\Data\ResponseData;
use Tsitsishvili\Documentator\Extraction\Support\RuleParser;

/**
 * Renders a list of EndpointData into an OpenAPI 3.2 document (a plain array
 * ready to be JSON-encoded and handed to Scalar).
 */
final class OpenApiGenerator
{
    /** @var array<string, int> */
    private array $componentCounts = [];

    /** @var array<string, string> */
    private array $componentNames = [];

    /** @var array<string, array<string, mixed>> */
    private array $componentSchemas = [];

    /** @var array<string, int> */
    private array $operationIds = [];

    /**
     * @param  array<int, EndpointData>  $endpoints
     * @return array<string, mixed>
     */
    public function generate(array $endpoints): array
    {
        $this->componentCounts = $this->componentCounts($endpoints);
        $this->componentNames = [];
        $this->componentSchemas = [];
        $this->operationIds = [];

        $paths = [];
        $tags = [];
        $globalScheme = $this->defaultSecurityScheme();

        foreach ($endpoints as $endpoint) {
            $path = $this->normalizePath($endpoint->uri);
            $tag = $this->tagFor($endpoint);
            $tagKey = $this->tagKey($tag, $endpoint->groupVersion);
            $tags[$tagKey] ??= $this->tag($tag, $endpoint->groupVersion, $endpoint->groupDescription);
            if ($endpoint->groupDescription !== null && ! isset($tags[$tagKey]['description'])) {
                $tags[$tagKey]['description'] = $endpoint->groupDescription;
            }

            foreach ($endpoint->verbs() as $verb) {
                $paths[$path][$verb] = $this->operation($endpoint, $tag, $globalScheme);
            }
        }

        $components = [
            'securitySchemes' => (object) config('documentator.security', []),
        ];

        if ($this->componentSchemas !== []) {
            ksort($this->componentSchemas);
            $components['schemas'] = $this->componentSchemas;
        }

        $spec = array_filter([
            'openapi' => '3.2.0',
            'info' => $this->info(),
            'servers' => config('documentator.servers', []),
            'security' => $globalScheme !== null ? [[$globalScheme => []]] : [],
            'tags' => array_values($tags),
            'x-documentator-global-path-parameters' => $this->globalPathParameters(),
            'paths' => $paths,
            'components' => $components,
        ]);

        return $this->transform($spec);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function transform(array $spec): array
    {
        foreach ((array) config('documentator.extensions.openapi_transformers', []) as $transformer) {
            $callback = is_string($transformer) ? app($transformer) : $transformer;

            if (is_callable($callback)) {
                $result = $callback($spec);

                if (is_array($result)) {
                    $spec = $result;
                }
            }
        }

        return $spec;
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
     *
     * @phpstan-impure
     */
    private function operation(EndpointData $endpoint, string $tag, ?string $globalScheme = null): array
    {
        $operation = array_filter([
            'operationId' => $this->operationId($endpoint),
            'tags' => [$tag],
            'summary' => $endpoint->summary,
            'description' => $endpoint->description,
            'parameters' => $this->parameters($endpoint),
            'requestBody' => $this->requestBody($endpoint),
            'responses' => $this->responses($endpoint),
            'servers' => $endpoint->servers,
            'deprecated' => $endpoint->deprecated ?: null,
            'x-documentator-section' => $this->sectionFor($endpoint),
            'x-documentator-group-version' => $endpoint->groupVersion,
        ], fn ($value) => $value !== null && $value !== []);

        if ($endpoint->authenticated) {
            $operation['security'] = [[$endpoint->securityScheme ?? 'default' => $endpoint->securityScopes]];
        } elseif ($globalScheme !== null) {
            // A global security requirement is in force; this operation isn't
            // authenticated, so opt it out with an empty requirement to keep it
            // public (overriding the document's root `security`).
            $operation['security'] = [];
        }

        return $operation;
    }

    private function operationId(EndpointData $endpoint): string
    {
        $base = $endpoint->operationId();
        $count = $this->operationIds[$base] ?? 0;
        $this->operationIds[$base] = $count + 1;

        return $count === 0 ? $base : $base.($count + 1);
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

        foreach ($endpoint->headerParameters as $param) {
            $parameters[] = $this->parameter($param, 'header', $param->required);
        }

        foreach ($endpoint->cookieParameters as $param) {
            $parameters[] = $this->parameter($param, 'cookie', $param->required);
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    private function parameter(ParameterData $param, string $in, bool $required): array
    {
        $schema = $this->schema($param);

        $parameter = array_filter([
            'name' => $param->name,
            'in' => $in,
            'required' => $required,
            'description' => $param->description,
            'schema' => $schema,
            'style' => $param->style,
            'explode' => $param->explode,
            'example' => $param->example ?? ($this->generatesExamples() ? SchemaSampler::sample($schema) : null),
        ], fn ($value) => $value !== null);

        if ($in === 'path' && $this->globalPathParameter($param->name) !== null) {
            $parameter['x-documentator-global'] = true;
        }

        return $parameter;
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

        $mediaType = $endpoint->requestMediaType ?? (RuleParser::hasUpload(array_values($endpoint->bodyParameters))
            ? 'multipart/form-data'
            : 'application/json');

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

        $mediaType = $response->mediaType ?? 'application/json';
        $content = [];

        if ($response->schema !== null) {
            $content['schema'] = $this->responseSchema($response);
        } elseif ($response->type !== null && ($typeSchema = TypeStringParser::parse($response->type)) !== null) {
            $content['schema'] = $this->normalizeSchema($typeSchema);
        } elseif ($response->resource !== null) {
            $content['schema'] = ['type' => 'object'];
        }

        if ($response->example !== null) {
            $content['example'] = $response->example;
        }

        if ($content !== []) {
            $body['content'] = [$mediaType => $content];
        }

        if ($response->headers !== []) {
            $body['headers'] = [];

            foreach ($response->headers as $header) {
                $body['headers'][$header->name] = array_filter([
                    'description' => $header->description,
                    'schema' => $this->schema($header),
                    'example' => $header->example,
                ], fn ($value) => $value !== null);
            }
        }

        return $body;
    }

    /**
     * @param  array<int, EndpointData>  $endpoints
     * @return array<string, int>
     */
    private function componentCounts(array $endpoints): array
    {
        $counts = [];

        foreach ($endpoints as $endpoint) {
            foreach ($endpoint->responses as $response) {
                $key = $this->componentKey($response);

                if ($key !== null) {
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(ResponseData $response): array
    {
        $key = $this->componentKey($response);

        if ($key === null || ($response->schemaName === null && ($this->componentCounts[$key] ?? 0) < 2)) {
            return $this->normalizeSchema($response->schema ?? ['type' => 'object']);
        }

        $name = $this->componentName($response, $key);
        $this->componentSchemas[$name] ??= $this->normalizeSchema($response->schema ?? ['type' => 'object']);

        return ['$ref' => '#/components/schemas/'.$name];
    }

    private function componentKey(ResponseData $response): ?string
    {
        if ($response->schemaName !== null && $response->schema !== null) {
            return $response->schemaName.'|'.$this->schemaKind($response->schema).'|'.md5(json_encode($response->schema) ?: '');
        }

        if ($response->resource === null || $response->schema === null) {
            return null;
        }

        return $response->resource.'|'.$this->schemaKind($response->schema).'|'.md5(json_encode($response->schema) ?: '');
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function schemaKind(array $schema): string
    {
        $properties = $schema['properties'] ?? [];

        if (isset($properties['data']['type']) && $properties['data']['type'] === 'array') {
            return isset($properties['meta']) ? 'Paginated' : 'Collection';
        }

        return 'Resource';
    }

    private function componentName(ResponseData $response, string $key): string
    {
        if (isset($this->componentNames[$key])) {
            return $this->componentNames[$key];
        }

        $base = $response->schemaName ?? Str::studly(class_basename((string) $response->resource));
        $kind = $this->schemaKind($response->schema ?? []);
        $name = $base.($kind === 'Resource' ? '' : $kind);

        if (isset($this->componentSchemas[$name]) && $this->componentSchemas[$name] !== $this->normalizeSchema($response->schema ?? [])) {
            $name .= substr(md5($key), 0, 8);
        }

        return $this->componentNames[$key] = $name;
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

            return $this->normalizeSchema($schema);
        }

        $schema = TypeStringParser::parse($param->type) ?? ['type' => $param->type];

        if (($schema['type'] ?? null) === 'array' && ! isset($schema['items'])) {
            $schema['items'] = ['type' => 'string'];
        }

        if ($param->description !== null) {
            $schema['description'] = $param->description;
        }

        if ($param->example !== null) {
            $schema['example'] = $param->example;
        }

        return $this->normalizeSchema($schema);
    }

    /**
     * OpenAPI 3.1+ is JSON Schema-based, so `type: ["string", "null"]` is the
     * native nullable representation. Extractors keep the simpler internal
     * `nullable` flag; the public document is normalized here.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema): array
    {
        foreach (['properties', 'oneOf', 'anyOf', 'allOf'] as $key) {
            if (! isset($schema[$key]) || ! is_array($schema[$key])) {
                continue;
            }

            foreach ($schema[$key] as $name => $child) {
                if (is_array($child)) {
                    $schema[$key][$name] = $this->normalizeSchema($child);
                }
            }
        }

        foreach (['items', 'additionalProperties'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                $schema[$key] = $this->normalizeSchema($schema[$key]);
            }
        }

        if (($schema['nullable'] ?? false) === true) {
            unset($schema['nullable']);

            if (isset($schema['type'])) {
                $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];

                if (! in_array('null', $types, true)) {
                    $types[] = 'null';
                }

                $schema['type'] = $types;
            } elseif (! isset($schema['oneOf'], $schema['anyOf'], $schema['allOf'])) {
                $schema['type'] = ['null'];
            }
        }

        return $schema;
    }

    private function normalizePath(string $uri): string
    {
        // Laravel optional params ({id?}) and binding fields ({post:slug})
        // aren't valid OpenAPI path templates.
        $path = (string) preg_replace('/\{(\w+)(?::[^}?]+)?\??\}/', '{$1}', $uri);

        return '/'.ltrim($path, '/');
    }

    private function sectionFor(EndpointData $endpoint): ?string
    {
        $uri = trim($endpoint->uri, '/');
        $first = Str::before($uri, '/');

        foreach ((array) config('documentator.grouping.sections', []) as $pattern => $label) {
            if (is_int($pattern)) {
                if (! is_string($label)) {
                    continue;
                }

                $pattern = $label;
                $label = Str::headline($label);
            }

            $pattern = trim((string) $pattern, '/');

            if ($pattern === '' || ! is_string($label)) {
                continue;
            }

            if ($pattern === $first || Str::is($pattern, $uri)) {
                return $label;
            }
        }

        return null;
    }

    private function tagFor(EndpointData $endpoint): string
    {
        if ($endpoint->group !== null) {
            return $endpoint->group;
        }

        $source = (string) config('documentator.grouping.source', 'auto');

        if ($source === 'path') {
            return $this->pathTagFor($endpoint) ?? $this->controllerTagFor($endpoint) ?? 'Endpoints';
        }

        if ($source === 'auto' && $endpoint->controller === null) {
            return $this->pathTagFor($endpoint) ?? 'Endpoints';
        }

        return $this->controllerTagFor($endpoint) ?? 'Endpoints';
    }

    private function controllerTagFor(EndpointData $endpoint): ?string
    {
        if ($endpoint->controller === null) {
            return null;
        }

        return Str::headline(Str::replaceLast('Controller', '', class_basename($endpoint->controller)));
    }

    private function pathTagFor(EndpointData $endpoint): ?string
    {
        $segments = [];
        $depth = max(1, (int) config('documentator.grouping.path_depth', 1));
        $ignorePrefixes = array_map('strtolower', (array) config('documentator.grouping.ignore_path_prefixes', ['api']));
        $ignorePathParameters = (bool) config('documentator.grouping.ignore_path_parameters', true);

        foreach (explode('/', trim($endpoint->uri, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            $parameter = $this->pathParameterName($segment);
            $normalized = strtolower($parameter ?? $segment);

            if ($parameter === null && in_array($normalized, $ignorePrefixes, true)) {
                continue;
            }

            if ($parameter !== null && ($ignorePathParameters || $this->globalPathParameterSkipsGrouping($parameter))) {
                continue;
            }

            $segments[] = $parameter ?? $segment;

            if (count($segments) >= $depth) {
                break;
            }
        }

        if ($segments === []) {
            return null;
        }

        return Str::headline(implode(' ', $segments));
    }

    private function pathParameterName(string $segment): ?string
    {
        if (preg_match('/^\{(\w+)(?::[^}?]+)?\??\}$/', $segment, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function globalPathParameterSkipsGrouping(string $name): bool
    {
        $parameter = $this->globalPathParameter($name);

        return is_array($parameter) && ($parameter['grouping'] ?? true) === false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function globalPathParameter(string $name): ?array
    {
        foreach ((array) config('documentator.global_path_parameters', []) as $key => $value) {
            if (is_int($key)) {
                if ($value === $name) {
                    return [];
                }

                continue;
            }

            if ($key === $name) {
                return is_array($value) ? $value : [];
            }
        }

        return null;
    }

    private function tagKey(string $name, ?string $version): string
    {
        return $version === null ? $name : $name.'|'.$version;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function globalPathParameters(): array
    {
        $parameters = [];

        foreach ((array) config('documentator.global_path_parameters', []) as $name => $value) {
            if (is_int($name)) {
                if (! is_string($value)) {
                    continue;
                }

                $name = $value;
                $value = [];
            }

            $config = is_array($value) ? $value : [];
            $parameter = array_filter([
                'description' => $config['description'] ?? null,
                'schema' => isset($config['schema']) && is_array($config['schema'])
                    ? $config['schema']
                    : (isset($config['type']) && is_string($config['type']) ? ['type' => $config['type']] : null),
                'example' => $config['example'] ?? null,
            ], fn ($field) => $field !== null);

            $parameters[(string) $name] = $parameter;
        }

        return $parameters;
    }

    /**
     * @return array<string, string>
     */
    private function tag(string $name, ?string $version, ?string $description = null): array
    {
        return array_filter([
            'name' => $name,
            'description' => $description,
            'x-documentator-version' => $version,
        ], fn ($value) => $value !== null);
    }
}
