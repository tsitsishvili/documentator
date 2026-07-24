<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Testing;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tsitsishvili\Documentator\Documentator;

/**
 * Bridges Laravel feature-test responses to the generated OpenAPI contract and
 * turns validation failures into normal PHPUnit assertion failures.
 */
final class TestResponseContract
{
    /** @var array<string, mixed>|null */
    private ?array $document = null;

    public function __construct(
        private readonly Documentator $documentator,
        private readonly OpenApiResponseValidator $validator,
    ) {}

    public function assert(TestResponse $response, ?string $method = null, ?string $uri = null): TestResponse
    {
        $method ??= $response->baseRequest?->getMethod();
        $uri ??= $response->baseRequest?->getPathInfo();

        if ($method === null || trim($method) === '' || $uri === null || trim($uri) === '') {
            Assert::fail(
                'Documentator could not determine the request method and URI. '.
                'Pass them explicitly: assertMatchesDocumentation(\'GET\', \'/api/example\').',
            );
        }

        $errors = $this->validator->validate(
            $this->document ??= $this->documentator->toOpenApi(),
            $method,
            $uri,
            $response->getStatusCode(),
            $response->headers->get('Content-Type'),
            $this->content($response),
        );

        Assert::assertTrue(
            $errors === [],
            'Response contract validation failed for '.strtoupper($method).' '.$uri.':'.
            PHP_EOL.' - '.implode(PHP_EOL.' - ', $errors),
        );

        return $response;
    }

    private function content(TestResponse $response): string|false
    {
        if ($response->baseResponse instanceof StreamedResponse
            || $response->baseResponse instanceof BinaryFileResponse) {
            return $response->streamedContent();
        }

        return $response->getContent();
    }
}
