<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Postman;

use Tsitsishvili\Documentator\OpenApi\OpenApiMethods;
use Tsitsishvili\Documentator\OpenApi\SchemaSampler;

/**
 * Converts a generated OpenAPI 3.2 document into a Postman Collection v2.1, so
 * consumers can import the API straight into Postman/Insomnia. Endpoints are
 * grouped into folders by tag; `{{baseUrl}}` and `{{token}}` are collection
 * variables.
 */
final class PostmanGenerator
{
    /**
     * @param  array<string, mixed>  $openapi
     * @return array<string, mixed>
     */
    public function generate(array $openapi): array
    {
        $info = $openapi['info'] ?? [];
        $servers = $openapi['servers'] ?? [];
        $baseUrl = $servers[0]['url'] ?? 'http://localhost';
        $rootSecurity = $openapi['security'] ?? [];
        // components.securitySchemes is emitted as a stdClass (so an empty map
        // serializes to `{}`); cast back to an array for the array-typed helpers.
        $securitySchemes = (array) ($openapi['components']['securitySchemes'] ?? []);

        $folders = [];
        foreach ($openapi['paths'] ?? [] as $path => $operations) {
            foreach ($operations as $verb => $operation) {
                if (! in_array($verb, OpenApiMethods::ALL, true)) {
                    continue;
                }
                $tag = $operation['tags'][0] ?? 'Endpoints';
                $version = $operation['x-documentator-group-version'] ?? null;
                $folder = $version === null ? $tag : "{$tag} {$version}";
                $folders[$folder][] = $this->request($path, $verb, $operation, $rootSecurity, $securitySchemes);
            }
        }

        $items = [];
        foreach ($folders as $tag => $requests) {
            $items[] = ['name' => $tag, 'item' => $requests];
        }

        return [
            'info' => array_filter([
                'name' => $info['title'] ?? 'API',
                'description' => $info['description'] ?? null,
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ], fn ($value) => $value !== null),
            'item' => $items,
            'variable' => [
                ['key' => 'baseUrl', 'value' => $baseUrl],
                ['key' => 'token', 'value' => ''],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<int, array<string, mixed>>  $rootSecurity
     * @param  array<string, array<string, mixed>>  $securitySchemes
     * @return array<string, mixed>
     */
    private function request(string $path, string $verb, array $operation, array $rootSecurity, array $securitySchemes): array
    {
        $parameters = $operation['parameters'] ?? [];

        $query = [];
        $variables = [];
        foreach ($parameters as $param) {
            if (($param['in'] ?? null) === 'query') {
                $query[] = array_filter([
                    'key' => $param['name'],
                    'value' => '',
                    'description' => $param['description'] ?? null,
                    'disabled' => ! ($param['required'] ?? false),
                ], fn ($value) => $value !== null);
            } elseif (($param['in'] ?? null) === 'path') {
                $variables[] = ['key' => $param['name'], 'value' => ''];
            }
        }

        $template = preg_replace('/\{(\w+)\}/', ':$1', $path);
        $segments = array_values(array_filter(explode('/', $template), fn ($s) => $s !== ''));
        $rawQuery = $query === [] ? '' : '?'.implode('&', array_map(fn ($q) => $q['key'].'=', $query));

        $headers = [['key' => 'Accept', 'value' => 'application/json']];
        $body = null;

        $content = $operation['requestBody']['content'] ?? [];
        if (isset($content['application/json']['schema'])) {
            $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $body = [
                'mode' => 'raw',
                'raw' => json_encode(SchemaSampler::sample($content['application/json']['schema']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'options' => ['raw' => ['language' => 'json']],
            ];
        } elseif (isset($content['multipart/form-data']['schema']) && is_array($content['multipart/form-data']['schema'])) {
            $body = [
                'mode' => 'formdata',
                'formdata' => $this->formData($content['multipart/form-data']['schema']),
            ];
        }

        $request = [
            'method' => strtoupper($verb),
            'header' => $headers,
            'url' => array_filter([
                'raw' => '{{baseUrl}}'.$template.$rawQuery,
                'host' => ['{{baseUrl}}'],
                'path' => $segments,
                'query' => $query === [] ? null : $query,
                'variable' => $variables === [] ? null : $variables,
            ], fn ($value) => $value !== null),
        ];

        $auth = $this->auth($operation, $rootSecurity, $securitySchemes);

        if ($auth !== null) {
            $request['auth'] = $auth;
        }
        if ($body !== null) {
            $request['body'] = $body;
        }

        return [
            'name' => $operation['summary'] ?? (strtoupper($verb).' '.$path),
            'request' => $request,
            'response' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, array<string, mixed>>
     */
    private function formData(array $schema): array
    {
        $fields = [];

        foreach (($schema['properties'] ?? []) as $name => $property) {
            if (! is_string($name) || ! is_array($property)) {
                continue;
            }

            if ($this->isFileSchema($property)) {
                $fields[] = [
                    'key' => $name,
                    'type' => 'file',
                    'src' => '',
                ];

                continue;
            }

            $value = SchemaSampler::sample($property, name: $name);
            $fields[] = [
                'key' => $name,
                'type' => 'text',
                'value' => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function isFileSchema(array $schema): bool
    {
        if (($schema['format'] ?? null) === 'binary') {
            return true;
        }

        return ($schema['type'] ?? null) === 'array'
            && is_array($schema['items'] ?? null)
            && ($schema['items']['format'] ?? null) === 'binary';
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<int, array<string, mixed>>  $rootSecurity
     * @param  array<string, array<string, mixed>>  $securitySchemes
     * @return array<string, mixed>|null
     */
    private function auth(array $operation, array $rootSecurity, array $securitySchemes): ?array
    {
        $requirements = array_key_exists('security', $operation)
            ? $operation['security']
            : $rootSecurity;

        if (! is_array($requirements) || $requirements === []) {
            return null;
        }

        foreach ($requirements as $requirement) {
            if (! is_array($requirement) || $requirement === []) {
                continue;
            }

            $name = array_key_first($requirement);

            if (! is_string($name)) {
                continue;
            }

            $scheme = $securitySchemes[$name] ?? [];

            return $this->authForScheme($scheme);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $scheme
     * @return array<string, mixed>
     */
    private function authForScheme(array $scheme): array
    {
        if (($scheme['type'] ?? null) === 'apiKey') {
            return [
                'type' => 'apikey',
                'apikey' => [
                    ['key' => 'key', 'value' => (string) ($scheme['name'] ?? 'X-API-Key'), 'type' => 'string'],
                    ['key' => 'value', 'value' => '{{token}}', 'type' => 'string'],
                    ['key' => 'in', 'value' => (string) ($scheme['in'] ?? 'header'), 'type' => 'string'],
                ],
            ];
        }

        if (($scheme['type'] ?? null) === 'http' && ($scheme['scheme'] ?? null) === 'basic') {
            return [
                'type' => 'basic',
                'basic' => [
                    ['key' => 'username', 'value' => '{{username}}', 'type' => 'string'],
                    ['key' => 'password', 'value' => '{{password}}', 'type' => 'string'],
                ],
            ];
        }

        return [
            'type' => 'bearer',
            'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
        ];
    }
}
