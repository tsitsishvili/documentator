<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/** Documents a response header for a specific status code. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class ResponseHeader
{
    public function __construct(
        public int $status,
        public string $name,
        public string $type = 'string',
        public ?string $description = null,
        public mixed $example = null,
    ) {}
}
