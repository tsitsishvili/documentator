<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/**
 * Marks the endpoint (or whole controller) as requiring authentication. The
 * `scheme` references a key in config('documentator.security').
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Authenticated
{
    public function __construct(public string $scheme = 'default') {}
}
