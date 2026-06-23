<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

/**
 * Wraps an item schema in Laravel's resource-collection envelopes.
 */
final class PaginationSchema
{
    /**
     * `{ "data": [ item ] }` — a non-paginated resource collection.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function collection(array $item): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => $item],
            ],
        ];
    }

    /**
     * `{ "data": [...], "links": {...}, "meta": {...} }` — a paginated collection.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function paginated(array $item): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => $item],
                'links' => [
                    'type' => 'object',
                    'properties' => [
                        'first' => ['type' => 'string', 'nullable' => true],
                        'last' => ['type' => 'string', 'nullable' => true],
                        'prev' => ['type' => 'string', 'nullable' => true],
                        'next' => ['type' => 'string', 'nullable' => true],
                    ],
                ],
                'meta' => [
                    'type' => 'object',
                    'properties' => [
                        'current_page' => ['type' => 'integer'],
                        'from' => ['type' => 'integer', 'nullable' => true],
                        'last_page' => ['type' => 'integer'],
                        'path' => ['type' => 'string'],
                        'per_page' => ['type' => 'integer'],
                        'to' => ['type' => 'integer', 'nullable' => true],
                        'total' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }
}
