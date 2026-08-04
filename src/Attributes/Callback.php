<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/**
 * Documents an out-of-band request triggered by the attributed operation.
 * The expression is an OpenAPI runtime expression resolving the callback URL.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class Callback
{
    public function __construct(
        public string $name,
        public string $expression,
        public string $method = 'post',
        public ?string $resource = null,
        public ?string $type = null,
        public string $mediaType = 'application/json',
        public int $responseStatus = 200,
        public string $responseDescription = 'Callback accepted',
        public ?string $summary = null,
        public ?string $description = null,
    ) {}
}
