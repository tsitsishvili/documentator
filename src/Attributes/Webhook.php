<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Attributes;

use Attribute;

/**
 * Declares a provider-initiated webhook under the OpenAPI root `webhooks` map.
 * The attributed route is only an anchor for discovery; it remains a normal
 * documented API operation.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class Webhook
{
    public function __construct(
        public string $name,
        public string $method = 'post',
        public ?string $resource = null,
        public ?string $type = null,
        public string $mediaType = 'application/json',
        public int $responseStatus = 200,
        public string $responseDescription = 'Webhook accepted',
        public ?string $summary = null,
        public ?string $description = null,
    ) {}
}
