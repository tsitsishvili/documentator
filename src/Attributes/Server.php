<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/** Adds an operation-level OpenAPI server override. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Server
{
    public function __construct(
        public string $url,
        public ?string $description = null,
    ) {}
}
