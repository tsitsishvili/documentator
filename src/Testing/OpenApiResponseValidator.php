<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Testing;

use JsonException;

/**
 * Resolves a real HTTP response to its OpenAPI operation and validates the
 * documented status, media type, and body schema.
 */
final class OpenApiResponseValidator
{
    public function __construct(private readonly JsonSchemaValidator $schemas) {}

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, string>
     */
    public function validate(
        array $document,
        string $method,
        string $uri,
        int $status,
        ?string $contentType,
        string|false $content,
    ): array {
        $method = strtolower($method);
        $path = $this->requestPath($uri);
        $pathMatch = $this->matchPath($document, $path);

        if (is_string($pathMatch)) {
            return [$pathMatch];
        }

        [$documentedPath, $pathItem] = $pathMatch;
        $operation = $pathItem[$method] ?? null;
        $label = strtoupper($method)." {$path}";

        if (! is_array($operation)) {
            return ["{$label}: method is not documented for matched path {$documentedPath}"];
        }

        $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
        $responseMatch = $this->matchResponse($responses, $status);

        if ($responseMatch === null) {
            $documented = implode(', ', array_keys($responses));

            return ["{$label}: returned undocumented status {$status}".($documented !== '' ? " (documented: {$documented})" : '')];
        }

        [$statusKey, $response] = $responseMatch;
        $response = $this->resolveResponse($document, $response);

        if (! is_array($response)) {
            return ["{$label}: documented response {$statusKey} cannot be resolved"];
        }

        $contentMap = is_array($response['content'] ?? null) ? $response['content'] : [];
        $hasBody = is_string($content) && $content !== '';

        if ($contentMap === []) {
            return $hasBody
                ? ["{$label}: status {$status} returned a body, but the documentation defines no response content"]
                : [];
        }

        if (! $hasBody) {
            return ["{$label}: status {$status} returned no body, but response content is documented"];
        }

        $actualMediaType = $this->normalizeMediaType($contentType);

        if ($actualMediaType === null) {
            return ["{$label}: response has a body but no Content-Type header"];
        }

        $mediaMatch = $this->matchMediaType($contentMap, $actualMediaType);

        if ($mediaMatch === null) {
            return [
                "{$label}: returned media type {$actualMediaType}, but status {$statusKey} documents ".
                implode(', ', array_keys($contentMap)),
            ];
        }

        [$documentedMediaType, $media] = $mediaMatch;

        if (! is_array($media) || ! array_key_exists('schema', $media)) {
            return [];
        }

        $value = $content;

        if ($this->isJsonMediaType($actualMediaType) || $this->isJsonMediaType($documentedMediaType)) {
            try {
                $value = json_decode($content, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                return ["{$label}: response body is not valid JSON ({$exception->getMessage()})"];
            }
        }

        return $this->schemas->validate($document, $value, $media['schema']);
    }

    private function requestPath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $uri;
        $path = '/'.ltrim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{0: string, 1: array<string, mixed>}|string
     */
    private function matchPath(array $document, string $requestPath): array|string
    {
        $paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];

        if (is_array($paths[$requestPath] ?? null)) {
            return [$requestPath, $paths[$requestPath]];
        }

        $matches = [];

        foreach ($paths as $documentedPath => $pathItem) {
            if (! is_string($documentedPath) || ! is_array($pathItem)) {
                continue;
            }

            $regex = $this->pathRegex($documentedPath);

            if (preg_match($regex, $requestPath) !== 1) {
                continue;
            }

            $placeholderCount = preg_match_all('/\{[^}]+\}/', $documentedPath);
            $literalLength = strlen((string) preg_replace('/\{[^}]+\}/', '', $documentedPath));
            $matches[] = [
                'path' => $documentedPath,
                'item' => $pathItem,
                'placeholders' => $placeholderCount,
                'literal_length' => $literalLength,
            ];
        }

        if ($matches === []) {
            return "No documented path matches {$requestPath}";
        }

        usort($matches, function (array $left, array $right): int {
            return [$left['placeholders'], -$left['literal_length']]
                <=> [$right['placeholders'], -$right['literal_length']];
        });

        $best = $matches[0];
        $ties = array_filter(
            $matches,
            fn (array $match): bool => $match['placeholders'] === $best['placeholders']
                && $match['literal_length'] === $best['literal_length'],
        );

        if (count($ties) > 1) {
            return "Request path {$requestPath} ambiguously matches ".implode(', ', array_column($ties, 'path'));
        }

        return [$best['path'], $best['item']];
    }

    private function pathRegex(string $path): string
    {
        $parts = preg_split('/(\{[^}]+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$path];
        $pattern = '';

        foreach ($parts as $part) {
            $pattern .= str_starts_with($part, '{') && str_ends_with($part, '}')
                ? '[^/]+'
                : preg_quote($part, '~');
        }

        return "~^{$pattern}/?$~u";
    }

    /**
     * @param  array<string|int, mixed>  $responses
     * @return array{0: string, 1: mixed}|null
     */
    private function matchResponse(array $responses, int $status): ?array
    {
        foreach ($responses as $key => $response) {
            if ((string) $key === (string) $status) {
                return [(string) $key, $response];
            }
        }

        $range = substr((string) $status, 0, 1).'XX';

        foreach ($responses as $key => $response) {
            if (strtoupper((string) $key) === $range) {
                return [(string) $key, $response];
            }
        }

        foreach ($responses as $key => $response) {
            if (strtolower((string) $key) === 'default') {
                return [(string) $key, $response];
            }
        }

        return null;
    }

    private function resolveResponse(array $document, mixed $response): mixed
    {
        if (! is_array($response) || ! isset($response['$ref'])) {
            return $response;
        }

        return $this->resolvePointer($document, (string) $response['$ref']);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array{0: string, 1: mixed}|null
     */
    private function matchMediaType(array $content, string $actual): ?array
    {
        foreach ($content as $documented => $media) {
            if (strtolower((string) $documented) === $actual) {
                return [(string) $documented, $media];
            }
        }

        foreach ($content as $documented => $media) {
            if ($this->mediaTypeMatches(strtolower((string) $documented), $actual)) {
                return [(string) $documented, $media];
            }
        }

        return null;
    }

    private function mediaTypeMatches(string $documented, string $actual): bool
    {
        if ($documented === '*/*') {
            return true;
        }

        if (str_ends_with($documented, '/*')) {
            return str_starts_with($actual, substr($documented, 0, -1));
        }

        if (str_contains($documented, '*+')) {
            [$prefix, $suffix] = explode('*', $documented, 2);

            return str_starts_with($actual, $prefix) && str_ends_with($actual, $suffix);
        }

        return false;
    }

    private function normalizeMediaType(?string $contentType): ?string
    {
        if ($contentType === null || trim($contentType) === '') {
            return null;
        }

        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    private function isJsonMediaType(string $mediaType): bool
    {
        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    private function resolvePointer(array $document, string $ref): mixed
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
