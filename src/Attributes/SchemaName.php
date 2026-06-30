<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/** Gives a resource/data response schema a stable reusable component name. */
#[Attribute(Attribute::TARGET_CLASS)]
final class SchemaName
{
    public function __construct(public string $name) {}
}
