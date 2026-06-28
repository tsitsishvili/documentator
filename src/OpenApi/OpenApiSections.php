<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

use Illuminate\Support\Str;

/**
 * Knows the configured documentation sections and can split an OpenAPI document
 * into the per-section documents served by the built-in UI.
 */
final class OpenApiSections
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /**
     * @return array<int, array{slug: string, label: string, pattern: string}>
     */
    public function all(): array
    {
        $sections = [];
        $seen = [];

        foreach ((array) config('documentator.grouping.sections', []) as $pattern => $label) {
            if (is_int($pattern)) {
                if (! is_string($label)) {
                    continue;
                }

                $pattern = $label;
                $label = Str::headline($label);
            }

            $pattern = trim((string) $pattern, '/');

            if ($pattern === '' || ! is_string($label) || trim($label) === '') {
                continue;
            }

            $baseSlug = Str::slug($label) ?: (Str::slug($pattern) ?: 'section');
            $slug = $baseSlug;
            $suffix = 2;

            while (isset($seen[$slug])) {
                $slug = $baseSlug.'-'.$suffix++;
            }

            $seen[$slug] = true;
            $sections[] = [
                'slug' => $slug,
                'label' => $label,
                'pattern' => $pattern,
            ];
        }

        return $sections;
    }

    /**
     * @return array{slug: string, label: string, pattern: string}|null
     */
    public function first(): ?array
    {
        return $this->all()[0] ?? null;
    }

    /**
     * @return array{slug: string, label: string, pattern: string}|null
     */
    public function find(string $slug): ?array
    {
        foreach ($this->all() as $section) {
            if ($section['slug'] === $slug) {
                return $section;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function split(array $spec): array
    {
        $documents = [];

        foreach ($this->all() as $section) {
            $documents[$section['slug']] = $this->filterForLabel($spec, $section['label']);
        }

        return $documents;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function filter(array $spec, string $slug): ?array
    {
        $section = $this->find($slug);

        if ($section === null) {
            return null;
        }

        return $this->filterForLabel($spec, $section['label']);
    }

    public function cachePath(string $path, string $slug): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'json';

        return dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'-'.$slug.'.'.$extension;
    }

    /**
     * @return array<string, mixed>
     */
    private function filterForLabel(array $spec, string $label): array
    {
        $filtered = $spec;
        $filtered['paths'] = [];
        $usedTags = [];

        foreach (($spec['paths'] ?? []) as $path => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            $baseItems = [];
            $operations = [];

            foreach ($pathItem as $method => $operation) {
                if (! in_array(strtolower((string) $method), self::METHODS, true)) {
                    $baseItems[$method] = $operation;

                    continue;
                }

                if (! is_array($operation) || ($operation['x-documentator-section'] ?? null) !== $label) {
                    continue;
                }

                $operations[$method] = $operation;

                foreach ((array) ($operation['tags'] ?? []) as $tag) {
                    if (is_string($tag) && $tag !== '') {
                        $usedTags[$tag] = true;
                    }
                }
            }

            if ($operations !== []) {
                $filtered['paths'][$path] = array_merge($baseItems, $operations);
            }
        }

        if (isset($filtered['tags']) && is_array($filtered['tags'])) {
            $filtered['tags'] = array_values(array_filter(
                $filtered['tags'],
                fn (mixed $tag): bool => is_array($tag)
                    && is_string($tag['name'] ?? null)
                    && isset($usedTags[$tag['name']]),
            ));
        }

        $filtered['x-documentator-section'] = $label;

        return $filtered;
    }
}
