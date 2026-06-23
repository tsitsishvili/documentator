<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Data;

/**
 * A single documented parameter (path, query or body field).
 *
 * `schema`, when present, is a complete OpenAPI schema for the parameter
 * (enums, formats, bounds, nested objects/arrays) and takes precedence over the
 * scalar `type` in the generator. Attribute-declared params leave it null and
 * fall back to a schema built from `type`.
 */
final class ParameterData
{
    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $required = false,
        public ?string $description = null,
        public mixed $example = null,
        public ?array $schema = null,
    ) {}
}
