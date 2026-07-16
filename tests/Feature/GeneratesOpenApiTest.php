<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Documentator;

it('documents a matched api route as an OpenAPI 3.2 path', function () {
    Route::get('api/orders/{order}', fn () => 'ok')->name('orders.show');

    $spec = app(Documentator::class)->toOpenApi();

    expect($spec['openapi'])->toBe('3.2.0')
        ->and($spec['paths'])->toHaveKey('/api/orders/{order}')
        ->and($spec['paths']['/api/orders/{order}'])->toHaveKey('get');
});

it('documents QUERY routes with request content', function () {
    Route::match(['QUERY'], 'api/orders/search', [QueryOrdersController::class, 'search']);

    $spec = app(Documentator::class)->toOpenApi();
    $operation = $spec['paths']['/api/orders/search']['query'];

    expect($operation['requestBody']['content']['application/json']['schema'])
        ->toMatchArray([
            'type' => 'object',
            'required' => ['term'],
            'properties' => [
                'term' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
        ])
        ->and($operation['responses'])->toHaveKey('200');
});

it('excludes routes that do not match the configured patterns', function () {
    Route::get('internal/health', fn () => 'ok');

    $spec = app(Documentator::class)->toOpenApi();

    expect($spec['paths'] ?? [])->not->toHaveKey('/internal/health');
});

class QueryOrdersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'term' => ['required', 'string'],
            'limit' => ['integer'],
        ];
    }
}

class QueryOrdersController
{
    public function search(QueryOrdersRequest $request): array
    {
        return ['orders' => []];
    }
}
