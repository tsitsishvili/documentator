<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/** Adds a description to the OpenAPI tag used for this endpoint group. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_CLASS)]
final class TagDescription
{
    public function __construct(public string $text) {}
}
