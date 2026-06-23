<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/** A short, one-line title for the endpoint. */
#[Attribute(Attribute::TARGET_METHOD)]
final class Summary
{
    public function __construct(public string $text) {}
}
