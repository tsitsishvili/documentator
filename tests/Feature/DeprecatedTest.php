<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\Deprecated;
use Tsitsishvili\Documentator\Documentator;

class LegacyController
{
    #[Deprecated]
    public function old(): void
    {
        //
    }
}

class CurrentController
{
    public function show(): void
    {
        //
    }
}

it('marks an operation deprecated', function () {
    Route::get('api/legacy', [LegacyController::class, 'old']);

    $op = app(Documentator::class)->toOpenApi()['paths']['/api/legacy']['get'];

    expect($op['deprecated'])->toBeTrue();
});

it('omits deprecated for normal operations', function () {
    Route::get('api/current', [CurrentController::class, 'show']);

    $op = app(Documentator::class)->toOpenApi()['paths']['/api/current']['get'];

    expect($op)->not->toHaveKey('deprecated');
});
