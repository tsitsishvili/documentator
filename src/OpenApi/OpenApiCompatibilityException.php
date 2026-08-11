<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

use RuntimeException;

/**
 * Raised when a generated document uses constructs unavailable in the selected
 * OpenAPI target and the caller did not explicitly opt into omitting them.
 */
final class OpenApiCompatibilityException extends RuntimeException
{
    /**
     * @param  array<int, string>  $issues
     */
    public function __construct(public readonly array $issues)
    {
        parent::__construct(
            'The document cannot be represented as the requested OpenAPI version:'.
            PHP_EOL.' - '.implode(PHP_EOL.' - ', $issues),
        );
    }
}
