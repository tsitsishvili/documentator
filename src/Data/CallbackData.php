<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Data;

/**
 * Explicit OpenAPI callback attached to a parent operation.
 */
final class CallbackData
{
    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(
        public string $name,
        public string $expression,
        public string $method = 'post',
        public ?string $type = null,
        public ?array $schema = null,
        public string $mediaType = 'application/json',
        public int $responseStatus = 200,
        public string $responseDescription = 'Callback accepted',
        public ?string $summary = null,
        public ?string $description = null,
    ) {}
}
