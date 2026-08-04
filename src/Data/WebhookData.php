<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Data;

/**
 * Explicit provider-initiated webhook emitted at the OpenAPI document root.
 */
final class WebhookData
{
    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(
        public string $name,
        public string $method = 'post',
        public ?string $type = null,
        public ?array $schema = null,
        public string $mediaType = 'application/json',
        public int $responseStatus = 200,
        public string $responseDescription = 'Webhook accepted',
        public ?string $summary = null,
        public ?string $description = null,
    ) {}
}
