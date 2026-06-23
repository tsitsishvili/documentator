<?php

declare(strict_types=1);

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\Documentator;

class OrderItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'sku' => $this->sku,
            'quantity' => (int) $this->quantity,
        ];
    }
}

class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'reference' => $this->reference,
            'paid' => (bool) $this->paid,
            'items' => OrderItemResource::collection($this->items),
            'created_at' => $this->created_at,
        ];
    }
}

class OrderShowController
{
    #[Response(200, resource: OrderResource::class)]
    public function show(): void
    {
        //
    }
}

it('extracts a real response schema from an API Resource, following nesting', function () {
    Route::get('api/orders/{order}', [OrderShowController::class, 'show']);

    $schema = app(Documentator::class)->toOpenApi()['paths']['/api/orders/{order}']['get']['responses']['200']['content']['application/json']['schema'];

    expect($schema['type'])->toBe('object')
        // Types inferred from casts / literals.
        ->and($schema['properties']['id']['type'])->toBe('integer')
        ->and($schema['properties']['reference']['type'])->toBe('string')
        ->and($schema['properties']['paid']['type'])->toBe('boolean')
        // Name-based fallback for an un-typed property fetch.
        ->and($schema['properties']['created_at'])->toBe(['type' => 'string', 'format' => 'date-time'])
        // Nested resource collection is followed into an array of objects.
        ->and($schema['properties']['items']['type'])->toBe('array')
        ->and($schema['properties']['items']['items']['properties']['sku']['type'])->toBe('string')
        ->and($schema['properties']['items']['items']['properties']['quantity']['type'])->toBe('integer');
});
