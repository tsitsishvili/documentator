<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tsitsishvili\Documentator\Documentator;
use Tsitsishvili\Documentator\OpenApi\OpenApiSections;

/**
 * Serves the generated OpenAPI document, from the cached file when enabled.
 */
final class OpenApiController
{
    public function __construct(
        private readonly Documentator $documentator,
        private readonly OpenApiSections $sections,
    ) {}

    public function show(?string $section = null): Response|JsonResponse
    {
        if ($section !== null && $this->sections->find($section) === null) {
            throw new NotFoundHttpException;
        }

        $cache = config('documentator.cache');

        if (($cache['enabled'] ?? false) && is_file($cache['path'])) {
            if ($section !== null) {
                $sectionPath = $this->sections->cachePath($cache['path'], $section);

                if (is_file($sectionPath)) {
                    return $this->jsonFile($sectionPath);
                }

                $cached = json_decode((string) file_get_contents($cache['path']), true);
                $filtered = is_array($cached) ? $this->sections->filter($cached, $section) : null;

                if ($filtered !== null) {
                    return $this->json($filtered);
                }
            }

            return new Response(
                (string) file_get_contents($cache['path']),
                200,
                ['Content-Type' => 'application/json'],
            );
        }

        $spec = $this->documentator->toOpenApi();
        $filtered = $section === null ? $spec : $this->sections->filter($spec, $section);

        return $this->json($filtered ?? []);
    }

    private function jsonFile(string $path): Response
    {
        return new Response(
            (string) file_get_contents($path),
            200,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function json(array $spec): JsonResponse
    {
        return new JsonResponse(
            $spec,
            200,
            [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
