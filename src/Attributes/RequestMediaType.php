<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/** Overrides the OpenAPI media type used for the request body. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class RequestMediaType
{
    public function __construct(public string $mediaType) {}
}
