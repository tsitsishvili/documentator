<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Support;

use Tsitsishvili\Documentator\OpenApi\OpenApiMethods;

/**
 * Lightweight OpenAPI 3.2 sanity checks for the document this package emits.
 * This is intentionally narrower than a full spec validator: it catches broken
 * refs and malformed path/operation/schema shapes before CI exports a bad file.
 */
final class OpenApiValidator
{
    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, string>
     */
    public static function validate(array $spec): array
    {
        $errors = [];
        $operationIds = [];

        if (($spec['openapi'] ?? null) !== '3.2.0') {
            $errors[] = 'openapi must be 3.2.0';
        }

        if (! is_array($spec['info'] ?? null)) {
            $errors[] = 'info must be an object';
        }

        if (! is_array($spec['paths'] ?? null)) {
            $errors[] = 'paths must be an object';

            return $errors;
        }

        foreach ($spec['paths'] as $path => $pathItem) {
            if (! is_string($path) || ! str_starts_with($path, '/')) {
                $errors[] = "path must start with /: {$path}";
            }

            if (! is_array($pathItem)) {
                $errors[] = "{$path} path item must be an object";

                continue;
            }

            $pathErrors = self::validatePathItem($spec, $path, $pathItem, $operationIds);
            $errors = array_merge($errors, $pathErrors);
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $pathItem
     * @return array<int, string>
     */
    private static function validatePathItem(array $spec, string $path, array $pathItem, array &$operationIds): array
    {
        $errors = [];
        $templateParameters = self::pathTemplateParameters($path);

        foreach ($pathItem as $verb => $operation) {
            if (! in_array($verb, OpenApiMethods::ALL, true)) {
                continue;
            }

            if (! is_array($operation)) {
                $errors[] = strtoupper((string) $verb)." {$path} operation must be an object";

                continue;
            }

            if (! is_array($operation['responses'] ?? null) || $operation['responses'] === []) {
                $errors[] = strtoupper((string) $verb)." {$path} must define responses";
            }

            if (is_string($operation['operationId'] ?? null) && $operation['operationId'] !== '') {
                $operationId = $operation['operationId'];

                if (isset($operationIds[$operationId])) {
                    $errors[] = "duplicate operationId {$operationId} on ".strtoupper((string) $verb)." {$path}";
                }

                $operationIds[$operationId] = true;
            }

            $definedPathParameters = [];

            foreach ($operation['parameters'] ?? [] as $parameter) {
                if (! is_array($parameter)) {
                    $errors[] = strtoupper((string) $verb)." {$path} parameter must be an object";

                    continue;
                }

                if (! is_string($parameter['name'] ?? null) || ! in_array($parameter['in'] ?? null, ['path', 'query', 'header', 'cookie'], true)) {
                    $errors[] = strtoupper((string) $verb)." {$path} parameter has invalid name or location";
                }

                if (($parameter['in'] ?? null) === 'path' && ($parameter['required'] ?? null) !== true) {
                    $errors[] = strtoupper((string) $verb)." {$path} path parameter {$parameter['name']} must be required";
                }

                if (($parameter['in'] ?? null) === 'path' && is_string($parameter['name'] ?? null)) {
                    $definedPathParameters[$parameter['name']] = true;
                }

                if (is_array($parameter['schema'] ?? null)) {
                    $errors = array_merge($errors, self::validateSchema($spec, "{$verb} {$path} parameter {$parameter['name']}", $parameter['schema']));
                }
            }

            foreach ($templateParameters as $name) {
                if (! self::validPathParameterName($name)) {
                    $errors[] = strtoupper((string) $verb)." {$path} path template parameter {$name} is not a valid OpenAPI parameter name";
                }

                if (! isset($definedPathParameters[$name])) {
                    $errors[] = strtoupper((string) $verb)." {$path} is missing path parameter {$name}";
                }
            }

            foreach (array_keys($definedPathParameters) as $name) {
                if (! in_array($name, $templateParameters, true)) {
                    $errors[] = strtoupper((string) $verb)." {$path} defines path parameter {$name} that is not present in the path template";
                }
            }

            foreach ($operation['requestBody']['content'] ?? [] as $mediaType => $content) {
                if (is_array($content['schema'] ?? null)) {
                    $errors = array_merge($errors, self::validateSchema($spec, "{$verb} {$path} request {$mediaType}", $content['schema']));
                }
            }

            foreach ($operation['responses'] ?? [] as $status => $response) {
                foreach ($response['content'] ?? [] as $mediaType => $content) {
                    if (is_array($content['schema'] ?? null)) {
                        $errors = array_merge($errors, self::validateSchema($spec, "{$verb} {$path} response {$status} {$mediaType}", $content['schema']));
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private static function pathTemplateParameters(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);

        return array_values(array_unique($matches[1]));
    }

    private static function validPathParameterName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]+$/', $name) === 1;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private static function validateSchema(array $spec, string $location, array $schema): array
    {
        $errors = [];

        if (array_key_exists('nullable', $schema)) {
            $errors[] = "{$location} uses legacy nullable instead of a 3.1 null union";
        }

        if (isset($schema['$ref']) && self::resolvePointer($spec, (string) $schema['$ref']) === null) {
            $errors[] = "{$location} has an unresolved ref {$schema['$ref']}";
        }

        foreach (['properties', 'oneOf', 'anyOf', 'allOf'] as $key) {
            foreach ($schema[$key] ?? [] as $name => $child) {
                if (is_array($child)) {
                    $errors = array_merge($errors, self::validateSchema($spec, "{$location}.{$name}", $child));
                }
            }
        }

        foreach (['items', 'additionalProperties'] as $key) {
            if (is_array($schema[$key] ?? null)) {
                $errors = array_merge($errors, self::validateSchema($spec, "{$location}.{$key}", $schema[$key]));
            }
        }

        return $errors;
    }

    private static function resolvePointer(array $document, string $ref): mixed
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $node = $document;

        foreach (explode('/', substr($ref, 2)) as $part) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $part);

            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }
}
