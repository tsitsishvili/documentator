<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

/**
 * Produces a representative sample value from an OpenAPI schema — used to seed
 * request body examples (e.g. for the exported Postman collection).
 */
final class SchemaSampler
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public static function sample(array $schema, int $depth = 0, ?string $name = null): mixed
    {
        if ($depth > 6) {
            return null;
        }

        if (array_key_exists('example', $schema)) {
            return $schema['example'];
        }

        if (! empty($schema['enum'])) {
            return $schema['enum'][0];
        }

        foreach (['oneOf', 'anyOf', 'allOf'] as $composite) {
            if (isset($schema[$composite][0]) && is_array($schema[$composite][0])) {
                return self::sample($schema[$composite][0], $depth + 1, $name);
            }
        }

        return match ($schema['type'] ?? null) {
            'object' => self::object($schema, $depth),
            'array' => array_fill(0, max(1, (int) ($schema['minItems'] ?? 1)), self::sample($schema['items'] ?? [], $depth + 1, $name)),
            'integer' => (int) ($schema['minimum'] ?? 1),
            'number' => (float) ($schema['minimum'] ?? 1),
            'boolean' => true,
            'string' => self::string($schema, $name),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function object(array $schema, int $depth): array
    {
        $sample = [];

        foreach (($schema['properties'] ?? []) as $name => $property) {
            if (is_array($property)) {
                $sample[$name] = self::sample($property, $depth + 1, (string) $name);
            }
        }

        return $sample;
    }

    /**
     * A representative string value, refined by the schema's `format`.
     *
     * @param  array<string, mixed>  $schema
     */
    private static function string(array $schema, ?string $name = null): string
    {
        $field = strtolower((string) $name);

        return match ($schema['format'] ?? null) {
            'email' => 'user@example.com',
            'uuid' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
            'uri', 'url' => 'https://example.com',
            'ipv4' => '127.0.0.1',
            'date-time' => '2026-01-01T00:00:00Z',
            'date' => '2026-01-01',
            'binary' => '',
            default => match (true) {
                $field === 'email' || str_ends_with($field, '_email') => 'user@example.com',
                $field === 'uuid' || str_ends_with($field, '_uuid') => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
                $field === 'url' || str_ends_with($field, '_url') => 'https://example.com',
                $field === 'name' || str_ends_with($field, '_name') => 'Example name',
                $field === 'title' => 'Example title',
                $field === 'description' => 'Example description',
                str_ends_with($field, '_at') => '2026-01-01T00:00:00Z',
                str_ends_with($field, '_date') => '2026-01-01',
                default => 'string',
            },
        };
    }
}
