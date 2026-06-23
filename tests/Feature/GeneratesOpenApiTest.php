<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Documentator;

it('documents a matched api route as an OpenAPI 3.1 path', function () {
    Route::get('api/orders/{order}', fn () => 'ok')->name('orders.show');

    $spec = app(Documentator::class)->toOpenApi();

    expect($spec['openapi'])->toBe('3.1.0')
        ->and($spec['paths'])->toHaveKey('/api/orders/{order}')
        ->and($spec['paths']['/api/orders/{order}'])->toHaveKey('get');
});

it('excludes routes that do not match the configured patterns', function () {
    Route::get('internal/health', fn () => 'ok');

    $spec = app(Documentator::class)->toOpenApi();

    expect($spec['paths'] ?? [])->not->toHaveKey('/internal/health');
});
