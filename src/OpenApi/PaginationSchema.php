<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;
use Tsitsishvili\Documentator\Data\ParameterData;

/**
 * Wraps an item schema in Laravel's resource-collection envelopes.
 */
final class PaginationSchema
{
    /**
     * The query parameters Laravel's paginator reads off the request, so a
     * paginated endpoint documents `?page=` and `?per_page=` without anyone
     * declaring them.
     *
     * @return array<string, ParameterData>
     */
    public static function queryParameters(): array
    {
        return [
            'page' => new ParameterData(
                name: 'page',
                type: 'integer',
                description: 'Page number of the paginated result set.',
                example: 1,
                schema: ['type' => 'integer', 'minimum' => 1],
            ),
            'per_page' => new ParameterData(
                name: 'per_page',
                type: 'integer',
                description: 'Number of items to return per page.',
                example: 15,
                schema: ['type' => 'integer', 'minimum' => 1],
            ),
        ];
    }

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
     * @param  class-string<ResourceCollection>|null  $collection
     * @return array<string, mixed>
     */
    public static function paginated(array $item, ?string $collection = null, ?bool $paginationLinks = null): array
    {
        $schema = [
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
                        'links' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'url' => ['type' => 'string', 'nullable' => true],
                                    'label' => ['type' => 'string'],
                                    'active' => ['type' => 'boolean'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if ($paginationLinks === false) {
            unset($schema['properties']['links'], $schema['properties']['meta']['properties']['links']);
        }

        return self::applyPaginationInformation($schema, $collection);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  class-string<ResourceCollection>|null  $collection
     * @return array<string, mixed>
     */
    private static function applyPaginationInformation(array $schema, ?string $collection): array
    {
        if ($collection === null || ! is_subclass_of($collection, ResourceCollection::class)) {
            return $schema;
        }

        $hasHook = method_exists($collection, 'paginationInformation')
            || (method_exists($collection, 'hasMacro') && $collection::hasMacro('paginationInformation'));

        if (! $hasHook) {
            return $schema;
        }

        try {
            /** @var ResourceCollection $resource */
            $resource = new $collection(self::samplePaginator());
            $information = $resource->paginationInformation(
                Request::create('/'),
                self::samplePaginated(),
                self::defaultPaginationInformation(),
            );
        } catch (Throwable) {
            return $schema;
        }

        return is_array($information) ? self::pruneToInformation($schema, $information) : $schema;
    }

    private static function samplePaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 15, 1, ['path' => '/']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function samplePaginated(): array
    {
        return self::samplePaginator()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultPaginationInformation(): array
    {
        $paginated = self::samplePaginated();

        return [
            'links' => [
                'first' => $paginated['first_page_url'] ?? null,
                'last' => $paginated['last_page_url'] ?? null,
                'prev' => $paginated['prev_page_url'] ?? null,
                'next' => $paginated['next_page_url'] ?? null,
            ],
            'meta' => array_diff_key($paginated, array_flip([
                'data',
                'first_page_url',
                'last_page_url',
                'prev_page_url',
                'next_page_url',
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $information
     * @return array<string, mixed>
     */
    private static function pruneToInformation(array $schema, array $information): array
    {
        foreach (array_keys($schema['properties']) as $name) {
            if ($name !== 'data' && ! array_key_exists($name, $information)) {
                unset($schema['properties'][$name]);
            }
        }

        foreach (['links', 'meta'] as $name) {
            if (
                isset($schema['properties'][$name]['properties'])
                && isset($information[$name])
                && is_array($information[$name])
            ) {
                foreach (array_keys($schema['properties'][$name]['properties']) as $property) {
                    if (! array_key_exists($property, $information[$name])) {
                        unset($schema['properties'][$name]['properties'][$property]);
                    }
                }
            }
        }

        return $schema;
    }
}
