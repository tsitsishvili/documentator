<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/**
 * Documents a possible response. Provide either a `resource` class (an API
 * Resource whose shape will be described) or an inline `example` payload. Set
 * `collection` to wrap the resource in a `{ data: [...] }` envelope, or
 * `paginated` for the full `{ data, links, meta }` paginator shape. Set
 * `paginationLinks: false` when a custom collection drops Laravel's link blocks.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Response
{
    public function __construct(
        public int $status = 200,
        public ?string $resource = null,
        public ?string $description = null,
        public mixed $example = null,
        public bool $collection = false,
        public bool $paginated = false,
        public ?bool $paginationLinks = null,
    ) {}
}
