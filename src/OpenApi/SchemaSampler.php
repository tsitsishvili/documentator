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
    public static function sample(array $schema, int $depth = 0): mixed
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

        return match ($schema['type'] ?? null) {
            'object' => array_map(
                fn (array $property) => self::sample($property, $depth + 1),
                $schema['properties'] ?? [],
            ),
            'array' => [self::sample($schema['items'] ?? [], $depth + 1)],
            'integer' => (int) ($schema['minimum'] ?? 1),
            'number' => (float) ($schema['minimum'] ?? 1),
            'boolean' => true,
            'string' => self::string($schema),
            default => null,
        };
    }

    /**
     * A representative string value, refined by the schema's `format`.
     *
     * @param  array<string, mixed>  $schema
     */
    private static function string(array $schema): string
    {
        return match ($schema['format'] ?? null) {
            'email' => 'user@example.com',
            'uuid' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
            'uri', 'url' => 'https://example.com',
            'ipv4' => '127.0.0.1',
            'date-time' => '2026-01-01T00:00:00Z',
            'date' => '2026-01-01',
            'binary' => '',
            default => 'string',
        };
    }
}
