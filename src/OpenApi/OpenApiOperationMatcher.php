<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

/**
 * Resolves a concrete request URI to its documented path template and
 * operation. Shared by runtime contract validation and example recording.
 */
final class OpenApiOperationMatcher
{
    /**
     * @param  array<string, mixed>  $document
     * @return array{path: string, request_path: string, operation: array<string, mixed>}|string
     */
    public function match(array $document, string $method, string $uri): array|string
    {
        $method = strtolower($method);
        $requestPath = $this->requestPath($uri);
        $pathMatch = $this->matchPath($document, $requestPath);

        if (is_string($pathMatch)) {
            return $pathMatch;
        }

        [$documentedPath, $pathItem] = $pathMatch;
        $operation = $pathItem[$method] ?? null;

        if (! is_array($operation)) {
            return strtoupper($method)." {$requestPath}: method is not documented for matched path {$documentedPath}";
        }

        return [
            'path' => $documentedPath,
            'request_path' => $requestPath,
            'operation' => $operation,
        ];
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

            if (preg_match($this->pathRegex($documentedPath), $requestPath) !== 1) {
                continue;
            }

            $matches[] = [
                'path' => $documentedPath,
                'item' => $pathItem,
                'placeholders' => preg_match_all('/\{[^}]+\}/', $documentedPath),
                'literal_length' => strlen((string) preg_replace('/\{[^}]+\}/', '', $documentedPath)),
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
}
