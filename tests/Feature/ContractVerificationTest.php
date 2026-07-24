<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\HttpFoundation\Response;
use Tsitsishvili\Documentator\Attributes\Response as DocumentedResponse;
use Tsitsishvili\Documentator\Testing\OpenApiResponseValidator;

class ContractVerificationController
{
    #[DocumentedResponse(200, type: 'array{data: array{id: int, email: email, nickname?: string}, tags: list<string>}')]
    public function valid(): JsonResponse
    {
        return response()->json([
            'data' => ['id' => 42, 'email' => 'ada@example.com'],
            'tags' => ['customer', 'active'],
        ]);
    }

    #[DocumentedResponse(200, type: 'array{data: array{id: int, email: email}, tags: list<string>}')]
    public function wrongShape(): JsonResponse
    {
        return response()->json([
            'data' => ['id' => 'forty-two'],
            'tags' => ['customer'],
        ]);
    }

    #[DocumentedResponse(200, type: 'array{id: int}')]
    public function wrongStatus(): JsonResponse
    {
        $status = (int) request()->query('status', 202);

        return response()->json(['id' => 42], $status);
    }

    #[DocumentedResponse(200, type: 'array{id: int}')]
    public function wrongMediaType(): HttpResponse
    {
        return response('{"id":42}', 200, ['Content-Type' => 'text/plain']);
    }

    #[DocumentedResponse(204)]
    public function emptyResponse(): HttpResponse
    {
        return response()->noContent();
    }
}

it('registers a fluent TestResponse assertion and resolves parameterized paths from the request', function () {
    Route::get('api/contracts/{contract}', [ContractVerificationController::class, 'valid']);

    expect(TestResponse::hasMacro('assertMatchesDocumentation'))->toBeTrue();

    $response = $this->getJson('/api/contracts/42')
        ->assertMatchesDocumentation()
        ->assertOk();

    expect($response)->toBeInstanceOf(TestResponse::class);
});

it('accepts explicit method and URI arguments when a TestResponse has no original request', function () {
    Route::get('api/contracts/{contract}', [ContractVerificationController::class, 'valid']);

    $response = TestResponse::fromBaseResponse(new Response(
        json_encode([
            'data' => ['id' => 42, 'email' => 'ada@example.com'],
            'tags' => ['customer'],
        ], JSON_THROW_ON_ERROR),
        200,
        ['Content-Type' => 'application/json; charset=UTF-8'],
    ));

    $response->assertMatchesDocumentation('GET', '/api/contracts/42?include=profile');
});

it('reports the exact nested field when a response body violates its schema', function () {
    Route::get('api/contracts/wrong-shape', [ContractVerificationController::class, 'wrongShape']);

    expect(fn () => $this->getJson('/api/contracts/wrong-shape')->assertMatchesDocumentation())
        ->toThrow(ExpectationFailedException::class, 'body.data.id: expected integer, got string');
});

it('reports undocumented statuses', function () {
    Route::get('api/contracts/wrong-status', [ContractVerificationController::class, 'wrongStatus']);

    expect(fn () => $this->getJson('/api/contracts/wrong-status')->assertMatchesDocumentation())
        ->toThrow(ExpectationFailedException::class, 'returned undocumented status 202');
});

it('reports undocumented media types', function () {
    Route::get('api/contracts/wrong-media', [ContractVerificationController::class, 'wrongMediaType']);

    expect(fn () => $this->get('/api/contracts/wrong-media')->assertMatchesDocumentation())
        ->toThrow(ExpectationFailedException::class, 'returned media type text/plain');
});

it('accepts documented responses with no content', function () {
    Route::delete('api/contracts/{contract}', [ContractVerificationController::class, 'emptyResponse']);

    $this->deleteJson('/api/contracts/42')
        ->assertMatchesDocumentation()
        ->assertNoContent();
});

it('validates refs, status ranges, media wildcards, composites, and common constraints', function () {
    $document = [
        'components' => [
            'schemas' => [
                'Payload' => [
                    'type' => 'object',
                    'required' => ['id', 'status', 'contact', 'choice', 'tags'],
                    'additionalProperties' => false,
                    'properties' => [
                        'id' => ['type' => 'integer', 'minimum' => 1],
                        'status' => ['type' => 'string', 'enum' => ['active', 'paused']],
                        'contact' => [
                            'allOf' => [
                                ['type' => 'object', 'required' => ['email']],
                                ['type' => 'object', 'properties' => ['email' => ['type' => 'string', 'format' => 'email']]],
                            ],
                        ],
                        'choice' => [
                            'oneOf' => [
                                ['type' => 'string', 'pattern' => '^ORD-'],
                                ['type' => 'integer', 'minimum' => 1],
                            ],
                        ],
                        'tags' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'uniqueItems' => true,
                            'items' => ['type' => 'string'],
                        ],
                        'note' => ['type' => ['string', 'null']],
                    ],
                ],
            ],
        ],
        'paths' => [
            '/api/contracts/{contract}' => [
                'get' => [
                    'responses' => [
                        '2XX' => [
                            'content' => [
                                'application/*+json' => [
                                    'schema' => ['$ref' => '#/components/schemas/Payload'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $validator = app(OpenApiResponseValidator::class);

    expect($validator->validate(
        $document,
        'GET',
        '/api/contracts/42',
        207,
        'application/problem+json',
        '{"id":42,"status":"active","contact":{"email":"ada@example.com"},"choice":"ORD-42","tags":["customer"],"note":null}',
    ))->toBe([]);

    $errors = $validator->validate(
        $document,
        'GET',
        '/api/contracts/42',
        207,
        'application/problem+json',
        '{"id":"42","status":"unknown","contact":{"email":"not-an-email"},"choice":false,"tags":["same","same"],"extra":true}',
    );

    expect($errors)
        ->toContain(
            'body.id: expected integer, got string',
            'body.status: value is not one of the documented enum values',
            'body.contact.email: string does not match the documented email format',
            'body.choice: value does not match any documented oneOf schema',
            'body.tags: array items must be unique',
            'body.extra: property is not allowed by the documented schema',
        );
});

it('reports invalid JSON before attempting schema validation', function () {
    $document = [
        'paths' => [
            '/api/contracts' => [
                'get' => [
                    'responses' => [
                        '200' => [
                            'content' => [
                                'application/json' => ['schema' => ['type' => 'object']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $errors = app(OpenApiResponseValidator::class)->validate(
        $document,
        'GET',
        '/api/contracts',
        200,
        'application/json',
        '{invalid',
    );

    expect($errors[0])->toContain('response body is not valid JSON');
});
