<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Testing;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tsitsishvili\Documentator\Documentator;

/**
 * Validates and records an existing Laravel feature-test response. It never
 * dispatches a request itself.
 */
final class TestResponseExampleRecorder
{
    public function __construct(
        private readonly Documentator $documentator,
        private readonly TestResponseContract $contract,
        private readonly RecordedExamples $examples,
    ) {}

    public function record(
        TestResponse $response,
        string $name = 'default',
        ?string $summary = null,
        ?string $method = null,
        ?string $uri = null,
    ): TestResponse {
        $method ??= $response->baseRequest?->getMethod();
        $uri ??= $response->baseRequest?->getPathInfo();

        if ($method === null || trim($method) === '' || $uri === null || trim($uri) === '') {
            Assert::fail(
                'Documentator could not determine the request method and URI. '.
                'Pass them explicitly to recordAsDocumentationExample().',
            );
        }

        $this->contract->assert($response, $method, $uri);
        $this->examples->record(
            $this->documentator->toOpenApi(),
            $method,
            $uri,
            $response->getStatusCode(),
            $response->headers->get('Content-Type'),
            $this->contract->content($response),
            $name,
            $summary,
        );

        return $response;
    }
}
