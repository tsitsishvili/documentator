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
            'integer', 'number' => 0,
            'boolean' => true,
            'string' => ($schema['format'] ?? null) === 'date-time' ? '2026-01-01T00:00:00Z' : 'string',
            default => null,
        };
    }
}
