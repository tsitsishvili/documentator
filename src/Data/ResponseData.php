<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Data;

/**
 * A single documented response for an endpoint.
 */
final class ResponseData
{
    /**
     * @param  array<string, mixed>|null  $schema  OpenAPI schema for the body
     */
    public function __construct(
        public int $status,
        public ?string $description = null,
        public mixed $example = null,
        public ?string $resource = null,
        public ?array $schema = null,
    ) {}
}
