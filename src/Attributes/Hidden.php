<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/** Excludes the endpoint (or whole controller) from the generated docs. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Hidden {}
