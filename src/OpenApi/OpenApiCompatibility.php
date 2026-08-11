<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

use InvalidArgumentException;

/**
 * Targets generated OpenAPI 3.2 documents at a supported downstream version.
 *
 * Extraction stays version-neutral. Compatibility is intentionally a final
 * projection so a 3.1 export cannot change the native 3.2 documentation.
 */
final class OpenApiCompatibility
{
    /** @var array<int, string> */
    public const TARGETS = ['3.1', '3.2'];

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function target(array $spec, string $target, bool $omitUnsupported = false): array
    {
        $target = $this->normalize($target);

        if ($target === '3.2') {
            $spec['openapi'] = '3.2.0';

            return $spec;
        }

        $issues = $this->issues($spec, $target);

        if ($issues !== [] && ! $omitUnsupported) {
            throw new OpenApiCompatibilityException($issues);
        }

        if ($omitUnsupported) {
            $spec = $this->withoutUnsupported($spec);
        }

        $spec['openapi'] = '3.1.0';

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, string>
     */
    public function issues(array $spec, string $target): array
    {
        $target = $this->normalize($target);

        if ($target === '3.2') {
            return [];
        }

        $issues = [];
        $this->inspectPathMap((array) ($spec['paths'] ?? []), 'paths', $issues);
        $this->inspectPathMap((array) ($spec['webhooks'] ?? []), 'webhooks', $issues);

        return array_values(array_unique($issues));
    }

    public function normalize(string $target): string
    {
        $target = trim($target);

        if (str_starts_with($target, '3.1')) {
            return '3.1';
        }

        if (str_starts_with($target, '3.2')) {
            return '3.2';
        }

        throw new InvalidArgumentException(
            'Unsupported OpenAPI target "'.$target.'". Supported targets: '.implode(', ', self::TARGETS).'.',
        );
    }

    /**
     * @param  array<string, mixed>  $pathMap
     * @param  array<int, string>  $issues
     */
    private function inspectPathMap(array $pathMap, string $location, array &$issues): void
    {
        foreach ($pathMap as $path => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            $label = $location.'.'.$path;

            if (isset($pathItem['query'])) {
                $issues[] = "{$label}.query uses HTTP QUERY, which requires OpenAPI 3.2";
            }

            foreach (OpenApiMethods::ALL as $method) {
                $operation = $pathItem[$method] ?? null;

                if (is_array($operation)) {
                    $this->inspectOperation($operation, "{$label}.{$method}", $issues);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<int, string>  $issues
     */
    private function inspectOperation(array $operation, string $location, array &$issues): void
    {
        $this->inspectContent(
            (array) ($operation['requestBody']['content'] ?? []),
            "{$location}.requestBody.content",
            $issues,
        );

        foreach ((array) ($operation['responses'] ?? []) as $status => $response) {
            if (is_array($response)) {
                $this->inspectContent(
                    (array) ($response['content'] ?? []),
                    "{$location}.responses.{$status}.content",
                    $issues,
                );
            }
        }

        foreach ((array) ($operation['callbacks'] ?? []) as $name => $callback) {
            if (! is_array($callback)) {
                continue;
            }

            $this->inspectPathMap($callback, "{$location}.callbacks.{$name}", $issues);
        }
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<int, string>  $issues
     */
    private function inspectContent(array $content, string $location, array &$issues): void
    {
        foreach ($content as $mediaType => $media) {
            if (! is_array($media)) {
                continue;
            }

            foreach (['itemSchema', 'itemEncoding', 'prefixEncoding'] as $field) {
                if (array_key_exists($field, $media)) {
                    $issues[] = "{$location}.{$mediaType}.{$field} requires OpenAPI 3.2";
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function withoutUnsupported(array $spec): array
    {
        $spec['paths'] = $this->cleanPathMap((array) ($spec['paths'] ?? []));

        if (isset($spec['webhooks']) && is_array($spec['webhooks'])) {
            $spec['webhooks'] = $this->cleanPathMap($spec['webhooks']);
        }

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $pathMap
     * @return array<string, mixed>
     */
    private function cleanPathMap(array $pathMap): array
    {
        foreach ($pathMap as $path => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            unset($pathItem['query']);

            foreach (OpenApiMethods::ALL as $method) {
                if (is_array($pathItem[$method] ?? null)) {
                    $pathItem[$method] = $this->cleanOperation($pathItem[$method]);
                }
            }

            if ($pathItem === []) {
                unset($pathMap[$path]);
            } else {
                $pathMap[$path] = $pathItem;
            }
        }

        return $pathMap;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function cleanOperation(array $operation): array
    {
        if (is_array($operation['requestBody']['content'] ?? null)) {
            $content = $this->cleanContent($operation['requestBody']['content']);

            if ($content === []) {
                unset($operation['requestBody']);
            } else {
                $operation['requestBody']['content'] = $content;
            }
        }

        foreach ((array) ($operation['responses'] ?? []) as $status => $response) {
            if (is_array($response) && is_array($response['content'] ?? null)) {
                $content = $this->cleanContent($response['content']);

                if ($content === []) {
                    unset($operation['responses'][$status]['content']);
                } else {
                    $operation['responses'][$status]['content'] = $content;
                }
            }
        }

        foreach ((array) ($operation['callbacks'] ?? []) as $name => $callback) {
            if (is_array($callback)) {
                $operation['callbacks'][$name] = $this->cleanPathMap($callback);
            }
        }

        return $operation;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function cleanContent(array $content): array
    {
        foreach ($content as $mediaType => $media) {
            if (! is_array($media)) {
                continue;
            }

            if (array_key_exists('itemSchema', $media)) {
                unset($content[$mediaType]);

                continue;
            }

            unset($media['itemEncoding'], $media['prefixEncoding']);

            if ($media === []) {
                unset($content[$mediaType]);

                continue;
            }

            $content[$mediaType] = $media;
        }

        return $content;
    }
}
