<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/**
 * Tells the resource-schema extractor which Eloquent model an API Resource
 * wraps, so property types can be read from the model's $casts. Optional — by
 * default the model is resolved by naming convention.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class UsesModel
{
    /**
     * @param  class-string  $model
     */
    public function __construct(public string $model) {}
}
