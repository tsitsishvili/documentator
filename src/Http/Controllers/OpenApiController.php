<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Tsitsishvili\Documentator\Documentator;

/**
 * Serves the generated OpenAPI document, from the cached file when enabled.
 */
final class OpenApiController
{
    public function __construct(private readonly Documentator $documentator) {}

    public function show(): Response|JsonResponse
    {
        $cache = config('documentator.cache');

        if (($cache['enabled'] ?? false) && is_file($cache['path'])) {
            return new Response(
                (string) file_get_contents($cache['path']),
                200,
                ['Content-Type' => 'application/json'],
            );
        }

        return new JsonResponse(
            $this->documentator->toOpenApi(),
            200,
            [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
