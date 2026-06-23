<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/** Marks the endpoint (or whole controller) as deprecated in the docs. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Deprecated {}
