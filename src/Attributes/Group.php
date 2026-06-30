<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/**
 * Groups the endpoint under a named tag in the UI. May be set per controller.
 *
 * The optional version lets multiple API versions share the same group name
 * without forcing names like "Products (v2)" into the public tag.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_CLASS)]
final class Group
{
    public function __construct(
        public string $name,
        public ?string $version = null,
    ) {}
}
