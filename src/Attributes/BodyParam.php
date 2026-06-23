<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/** Documents or overrides a request body field. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class BodyParam
{
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $required = false,
        public ?string $description = null,
        public mixed $example = null,
    ) {}
}
