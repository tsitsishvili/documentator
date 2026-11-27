<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Documentator;

class NestedClosureResource extends JsonResource
{
    public function toArray($request): array
    {
        $tags = collect($this->tags)->map(function ($tag) {
            return $tag->name;
        });

        return ['id' => (int) $this->id, 'tags' => $tags];
    }
}

class NestedClosureController
{
    public function inline(): JsonResponse
    {
        collect([1, 2])->map(function (int $i) {
            return ['inner' => $i];
        });

        return response()->json(['ok' => true]);
    }

    public function untyped()
    {
        collect([1, 2])->each(function (int $i) {
            return false;
        });

        return new NestedClosureResource((object) ['id' => 1, 'tags' => []]);
    }
}

it('ignores return statements that belong to nested closures', function () {
    Route::get('api/nested/inline', [NestedClosureController::class, 'inline']);
    Route::get('api/nested/untyped', [NestedClosureController::class, 'untyped']);

    $paths = app(Documentator::class)->toOpenApi()['paths'];
    $inline = $paths['/api/nested/inline']['get']['responses'][200]['content']['application/json']['schema'];
    $untyped = $paths['/api/nested/untyped']['get']['responses'][200]['content']['application/json']['schema']['properties']['data'];

    // Only the action's own return is a response, not the callback's.
    expect($inline)->not->toHaveKey('oneOf')
        ->and(array_keys($inline['properties']))->toBe(['ok']);

    // The resource is found past the callback, and its own toArray() callback
    // does not hide the returned array.
    expect(array_keys($untyped['properties']))->toBe(['id', 'tags']);
});
