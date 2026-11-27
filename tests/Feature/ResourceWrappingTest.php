<?php

declare(strict_types=1);

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\Response as DocumentedResponse;
use Tsitsishvili\Documentator\Documentator;

class WrappedResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => (int) $this->id];
    }
}

class WrappingController
{
    public function show(): WrappedResource
    {
        return new WrappedResource((object) ['id' => 1]);
    }

    #[DocumentedResponse(200, resource: WrappedResource::class)]
    public function attributed()
    {
        return new WrappedResource((object) ['id' => 1]);
    }
}

function wrappingSchema(string $path): array
{
    $spec = app(Documentator::class)->toOpenApi();
    $schema = $spec['paths'][$path]['get']['responses'][200]['content']['application/json']['schema'];

    return $spec['components']['schemas'][basename($schema['$ref'] ?? '')] ?? $schema;
}

afterEach(fn () => JsonResource::wrap('data'));

it('documents a single resource inside the data wrapper Laravel sends', function () {
    Route::get('api/wrapping', [WrappingController::class, 'show']);
    Route::get('api/wrapping/attributed', [WrappingController::class, 'attributed']);

    expect(array_keys(wrappingSchema('/api/wrapping')['properties']))->toBe(['data'])
        ->and(array_keys(wrappingSchema('/api/wrapping')['properties']['data']['properties']))->toBe(['id'])
        ->and(array_keys(wrappingSchema('/api/wrapping/attributed')['properties']))->toBe(['data']);

    // The real response matches what is documented.
    $this->getJson('api/wrapping')->assertExactJson(['data' => ['id' => 1]])->assertMatchesDocumentation();
});

it('documents a bare resource when wrapping is disabled', function () {
    JsonResource::withoutWrapping();
    Route::get('api/wrapping', [WrappingController::class, 'show']);

    expect(array_keys(wrappingSchema('/api/wrapping')['properties']))->toBe(['id']);

    $this->getJson('api/wrapping')->assertExactJson(['id' => 1])->assertMatchesDocumentation();
});
