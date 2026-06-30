<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/** A longer, multi-line explanation (Markdown supported by the UI). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class Description
{
    public function __construct(public string $text) {}
}
