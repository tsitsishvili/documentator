<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\BodyParam;
use Tsitsishvili\Documentator\Attributes\Group;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\Attributes\Summary;
use Tsitsishvili\Documentator\Documentator;

/*
 * Fixtures: a real FormRequest + controller exercising inference and overrides.
 */
class CreateThingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'qty' => 'required|integer',
        ];
    }
}

class ThingController
{
    #[Group('Things')]
    #[Summary('Create a thing')]
    #[BodyParam('name', 'string', required: true, description: 'Overridden name')]
    #[Response(201, description: 'Created')]
    public function store(CreateThingRequest $request): void
    {
        //
    }
}

#[Group('Products', version: 'v1')]
class VersionedProductsV1Controller
{
    public function index(): void
    {
        //
    }
}

#[Group('Products', version: 'v2')]
class VersionedProductsV2Controller
{
    public function index(): void
    {
        //
    }
}

it('infers body params from a FormRequest and applies attribute overrides', function () {
    Route::post('api/things', [ThingController::class, 'store']);

    $operation = app(Documentator::class)->toOpenApi()['paths']['/api/things']['post'];

    // Inference: qty came from rules() with the right type + required flag.
    $props = $operation['requestBody']['content']['application/json']['schema']['properties'];
    expect($props)->toHaveKeys(['name', 'qty'])
        ->and($props['qty']['type'])->toBe('integer')
        ->and($operation['requestBody']['content']['application/json']['schema']['required'])->toContain('qty');

    // Overrides: attribute values win over inference.
    expect($operation['summary'])->toBe('Create a thing')
        ->and($operation['tags'])->toBe(['Things'])
        ->and($props['name']['description'])->toBe('Overridden name')
        ->and($operation['responses'])->toHaveKey('201');
});

it('lets API versions live on groups without changing the group name', function () {
    Route::get('api/v1/products', [VersionedProductsV1Controller::class, 'index']);
    Route::get('api/v2/products', [VersionedProductsV2Controller::class, 'index']);

    $spec = app(Documentator::class)->toOpenApi();
    $v1 = $spec['paths']['/api/v1/products']['get'];
    $v2 = $spec['paths']['/api/v2/products']['get'];

    expect($v1['tags'])->toBe(['Products'])
        ->and($v1['x-documentator-group-version'])->toBe('v1')
        ->and($v1['operationId'])->toBe('v1VersionedProductsV1ControllerIndex')
        ->and($v2['tags'])->toBe(['Products'])
        ->and($v2['x-documentator-group-version'])->toBe('v2')
        ->and($v2['operationId'])->toBe('v2VersionedProductsV2ControllerIndex')
        ->and($spec['tags'])->toContain([
            'name' => 'Products',
            'x-documentator-version' => 'v1',
        ])
        ->and($spec['tags'])->toContain([
            'name' => 'Products',
            'x-documentator-version' => 'v2',
        ]);
});
