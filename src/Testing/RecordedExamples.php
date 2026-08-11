<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Testing;

use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Tsitsishvili\Documentator\OpenApi\OpenApiOperationMatcher;

/**
 * Persists explicitly captured feature-test responses and overlays them onto
 * the matching response Media Type Object as named OpenAPI examples.
 */
final class RecordedExamples
{
    public function __construct(private readonly OpenApiOperationMatcher $operations) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function record(
        array $document,
        string $method,
        string $uri,
        int $status,
        ?string $contentType,
        string|false $content,
        string $name = 'default',
        ?string $summary = null,
    ): void {
        $match = $this->operations->match($document, $method, $uri);

        if (is_string($match)) {
            throw new RuntimeException($match);
        }

        $statusKey = $this->statusKey((array) ($match['operation']['responses'] ?? []), $status);

        if ($statusKey === null) {
            throw new RuntimeException(strtoupper($method)." {$uri} returned undocumented status {$status}");
        }

        $mediaType = $this->mediaType(
            (array) ($match['operation']['responses'][$statusKey]['content'] ?? []),
            $contentType,
        );

        if ($mediaType === null) {
            throw new RuntimeException(strtoupper($method)." {$uri} returned an undocumented media type");
        }

        $value = $this->value($content, $contentType);
        $key = strtoupper($method).' '.$match['path'];
        $examples = $this->read();
        $exampleName = $this->name($name);
        $examples[$key][$statusKey][$mediaType][$exampleName] = array_filter([
            'summary' => $summary ?? ($name === 'default' ? null : $name),
            'value' => $this->redact($value),
        ], fn (mixed $item): bool => $item !== null);

        $this->write($examples);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function apply(array $document): array
    {
        foreach ($this->read() as $operationKey => $statuses) {
            if (! is_string($operationKey) || ! is_array($statuses)) {
                continue;
            }

            [$method, $path] = array_pad(explode(' ', $operationKey, 2), 2, null);
            $method = strtolower((string) $method);

            if (! is_string($path) || ! is_array($document['paths'][$path][$method] ?? null)) {
                continue;
            }

            foreach ($statuses as $status => $mediaTypes) {
                foreach ((array) $mediaTypes as $mediaType => $examples) {
                    if (! is_array($document['paths'][$path][$method]['responses'][$status]['content'][$mediaType] ?? null)
                        || ! is_array($examples)) {
                        continue;
                    }

                    unset($document['paths'][$path][$method]['responses'][$status]['content'][$mediaType]['example']);
                    $document['paths'][$path][$method]['responses'][$status]['content'][$mediaType]['examples'] = $examples;
                }
            }
        }

        return $document;
    }

    /**
     * @param  array<string|int, mixed>  $responses
     */
    private function statusKey(array $responses, int $status): string|int|null
    {
        foreach (array_keys($responses) as $key) {
            if ((string) $key === (string) $status) {
                return $key;
            }
        }

        $range = substr((string) $status, 0, 1).'XX';

        foreach (array_keys($responses) as $key) {
            if (strtoupper((string) $key) === $range) {
                return $key;
            }
        }

        foreach (array_keys($responses) as $key) {
            if (strtolower((string) $key) === 'default') {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function mediaType(array $content, ?string $actual): ?string
    {
        $actual = $this->normalizeMediaType($actual);

        if ($actual === null) {
            return null;
        }

        foreach (array_keys($content) as $documented) {
            if (strtolower((string) $documented) === $actual) {
                return (string) $documented;
            }
        }

        foreach (array_keys($content) as $documented) {
            $documented = strtolower((string) $documented);

            if ($documented === '*/*'
                || (str_ends_with($documented, '/*') && str_starts_with($actual, substr($documented, 0, -1)))
                || (str_contains($documented, '*+')
                    && str_starts_with($actual, explode('*', $documented, 2)[0])
                    && str_ends_with($actual, explode('*', $documented, 2)[1]))) {
                return (string) $documented;
            }
        }

        return null;
    }

    private function value(string|false $content, ?string $contentType): mixed
    {
        $content = is_string($content) ? $content : '';
        $mediaType = $this->normalizeMediaType($contentType);

        if ($mediaType === 'application/json' || ($mediaType !== null && str_ends_with($mediaType, '+json'))) {
            try {
                return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $content;
            }
        }

        return $content;
    }

    private function normalizeMediaType(?string $contentType): ?string
    {
        if ($contentType === null || trim($contentType) === '') {
            return null;
        }

        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    private function name(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($name)) ?: 'default';

        return trim($name, '-') ?: 'default';
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        $redacted = array_map('strtolower', (array) config('documentator.examples.redact', []));

        if ($key !== null && in_array(strtolower($key), $redacted, true)) {
            return '[REDACTED]';
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $childKey => $child) {
            $value[$childKey] = $this->redact($child, is_string($childKey) ? $childKey : null);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $path = $this->path();

        if (! File::exists($path)) {
            return [];
        }

        try {
            $decoded = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $examples
     */
    private function write(array $examples): void
    {
        $path = $this->path();
        File::ensureDirectoryExists(dirname($path));
        $json = json_encode(
            $examples,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
        if (File::put($path, $json, true) === false) {
            throw new RuntimeException("Could not write recorded examples to {$path}");
        }
    }

    private function path(): string
    {
        return (string) config('documentator.examples.path', storage_path('app/documentator/examples.json'));
    }
}
