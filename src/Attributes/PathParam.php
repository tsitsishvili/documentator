<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/** Documents or overrides a route path parameter (e.g. {order}). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class PathParam
{
    public function __construct(
        public string $name,
        public string $type = 'string',
        public ?string $description = null,
        public mixed $example = null,
    ) {}
}
