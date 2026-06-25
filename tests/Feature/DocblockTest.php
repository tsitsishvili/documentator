<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\Summary;
use Tsitsishvili\Documentator\Documentator;

class DocblockController
{
    /**
     * Publish the article.
     *
     * Flips the article to `published` and notifies subscribers.
     * Idempotent — re-publishing is a no-op.
     */
    public function publish(int $id): void
    {
        //
    }

    public function annotationsOnly(): void
    {
        //
    }

    #[Summary('Overridden summary')]
    /**
     * Docblock summary.
     */
    public function overridden(): void
    {
        //
    }
}

it('reads the summary and description from the controller docblock', function () {
    Route::get('api/articles/{id}/publish', [DocblockController::class, 'publish']);

    $op = app(Documentator::class)->toOpenApi()['paths']['/api/articles/{id}/publish']['get'];

    expect($op['summary'])->toBe('Publish the article.')
        ->and($op['description'])->toBe("Flips the article to `published` and notifies subscribers.\nIdempotent — re-publishing is a no-op.");
});

it('keeps the humanised summary when the docblock is only annotations', function () {
    Route::get('api/anno', [DocblockController::class, 'annotationsOnly']);

    $op = app(Documentator::class)->toOpenApi()['paths']['/api/anno']['get'];

    expect($op['summary'])->toBe('Annotations Only')
        ->and($op)->not->toHaveKey('description');
});

it('lets #[Summary] override the docblock summary', function () {
    Route::get('api/over', [DocblockController::class, 'overridden']);

    $op = app(Documentator::class)->toOpenApi()['paths']['/api/over']['get'];

    expect($op['summary'])->toBe('Overridden summary');
});
