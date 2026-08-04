<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tsitsishvili\Documentator\Attributes\Callback;
use Tsitsishvili\Documentator\Attributes\OperationId;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\Attributes\Webhook;
use Tsitsishvili\Documentator\Documentator;
use Tsitsishvili\Documentator\OpenApi\OpenApiCompatibilityException;
use Tsitsishvili\Documentator\Support\OpenApiDiff;
use Tsitsishvili\Documentator\Support\OpenApiValidator;
use Tsitsishvili\Documentator\TypeScript\TypeScriptClientGenerator;

class EventContractsController
{
    #[OperationId('streamEvents')]
    #[Callback(
        name: 'deliveryStatus',
        expression: '{$request.body#/callback_url}',
        type: 'array{event_id: int, status: string}',
        responseStatus: 204,
        responseDescription: 'Status received',
        summary: 'Delivery status callback',
    )]
    #[Webhook(
        name: 'event.created',
        type: 'array{event_id: int, name: string}',
        responseStatus: 202,
        responseDescription: 'Event accepted',
        summary: 'Event created',
    )]
    #[Response(
        200,
        type: 'array{id: int, message: string}',
        description: 'Continuous event stream',
        mediaType: 'application/x-ndjson',
        stream: true,
    )]
    public function stream(): StreamedResponse
    {
        return response()->stream(fn () => null, headers: ['Content-Type' => 'application/x-ndjson']);
    }
}

it('emits explicit callbacks, top-level webhooks, and streaming item schemas', function () {
    Route::get('api/events/stream', [EventContractsController::class, 'stream']);

    $spec = app(Documentator::class)->toOpenApi();
    $operation = $spec['paths']['/api/events/stream']['get'];
    $stream = $operation['responses'][200]['content']['application/x-ndjson'];
    $callback = $operation['callbacks']['deliveryStatus']['{$request.body#/callback_url}']['post'];
    $webhook = $spec['webhooks']['event.created']['post'];

    expect($stream)
        ->toHaveKey('itemSchema')
        ->not->toHaveKey('schema')
        ->and($stream['itemSchema']['properties']['id']['type'])->toBe('integer')
        ->and($callback['requestBody']['content']['application/json']['schema']['properties']['status']['type'])->toBe('string')
        ->and($callback['responses'][204]['description'])->toBe('Status received')
        ->and($webhook['operationId'])->toBe('webhookEventCreated')
        ->and($webhook['responses'][202]['description'])->toBe('Event accepted')
        ->and(OpenApiValidator::validate($spec))->toBe([]);
});

it('reports streaming fields as incompatible with OpenAPI 3.1 while preserving out-of-band contracts when omitted', function () {
    Route::get('api/events/stream', [EventContractsController::class, 'stream']);

    expect(fn () => app(Documentator::class)->toOpenApi('3.1'))
        ->toThrow(OpenApiCompatibilityException::class, 'itemSchema requires OpenAPI 3.2');

    $spec = app(Documentator::class)->toOpenApi('3.1', omitUnsupported: true);

    expect($spec['openapi'])->toBe('3.1.0')
        ->and($spec['paths']['/api/events/stream']['get']['responses'][200])
        ->not->toHaveKey('content')
        ->and($spec)->toHaveKey('webhooks')
        ->and($spec['paths']['/api/events/stream']['get'])->toHaveKey('callbacks')
        ->and(OpenApiValidator::validate($spec))->toBe([]);
});

it('tracks webhook, callback, and stream item drift', function () {
    Route::get('api/events/stream', [EventContractsController::class, 'stream']);

    $expected = app(Documentator::class)->toOpenApi();
    $actual = $expected;
    unset($actual['webhooks']['event.created']);
    unset($actual['paths']['/api/events/stream']['get']['callbacks']['deliveryStatus']);
    $actual['paths']['/api/events/stream']['get']['responses'][200]['content']['application/x-ndjson']['itemSchema']['properties']['id']['type'] = 'string';

    $changes = collect(OpenApiDiff::compare($expected, $actual))->pluck('message')->all();

    expect($changes)->toContain(
        'webhook removed',
        'callback removed: deliveryStatus',
        'schema type changed (integer -> string)',
    );
});

it('returns streaming responses as readable streams in the generated TypeScript client', function () {
    Route::get('api/events/stream', [EventContractsController::class, 'stream']);

    $source = app(TypeScriptClientGenerator::class)->generate(app(Documentator::class)->toOpenApi());

    expect($source)->toContain(
        'export type StreamEventsResponseItem = { id: number; message: string; };',
        'export type StreamEventsResponse = ReadableStream<Uint8Array>;',
        'async streamEvents(): Promise<StreamEventsResponse>',
        '"stream"',
        'return response.body as unknown as T;',
    );
});
