<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Documentator;

it('omits a root security requirement when not globally authenticated', function () {
    Route::get('api/ping', fn () => 'ok');

    $spec = app(Documentator::class)->toOpenApi();

    expect($spec)->not->toHaveKey('security')
        ->and($spec['paths']['/api/ping']['get'])->not->toHaveKey('security');
});

it('applies a global security requirement to the whole document', function () {
    config(['documentator.authenticate' => true]);

    Route::get('api/ping', fn () => 'ok');

    $spec = app(Documentator::class)->toOpenApi();

    expect($spec['security'])->toBe([['default' => []]]);
});

it('marks non-authenticated operations public when global auth is on', function () {
    config(['documentator.authenticate' => true]);

    Route::get('api/public', fn () => 'ok');                    // no auth middleware
    Route::get('api/private', fn () => 'ok')->middleware('auth'); // authenticated

    $paths = app(Documentator::class)->toOpenApi()['paths'];

    // Public endpoint opts out of the global requirement.
    expect($paths['/api/public']['get']['security'])->toBe([]);

    // Authenticated endpoint still states its own requirement.
    expect($paths['/api/private']['get']['security'])->toBe([['default' => []]]);
});

it('uses a named scheme for the global requirement', function () {
    config(['documentator.authenticate' => 'oauth']);

    Route::get('api/ping', fn () => 'ok');

    $spec = app(Documentator::class)->toOpenApi();

    expect($spec['security'])->toBe([['oauth' => []]])
        ->and($spec['paths']['/api/ping']['get']['security'])->toBe([]);
});
