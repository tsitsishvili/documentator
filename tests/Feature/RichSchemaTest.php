<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Documentator;

class RichRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'role' => 'required|in:admin,editor,viewer',
            'age' => 'nullable|integer|min:18|max:120',
            'avatar' => 'required|image',
            'items' => 'required|array',
            'items.*.sku' => 'required|string',
            'items.*.qty' => 'required|integer|min:1',
        ];
    }
}

class RichStoreController
{
    public function store(RichRequest $request): void
    {
        //
    }
}

it('infers enums, formats, bounds, nullability, nesting and uploads', function () {
    Route::post('api/rich', [RichStoreController::class, 'store']);

    $body = app(Documentator::class)->toOpenApi()['paths']['/api/rich']['post']['requestBody'];

    // An image field promotes the whole body to multipart.
    expect($body['content'])->toHaveKey('multipart/form-data');
    $schema = $body['content']['multipart/form-data']['schema'];
    $props = $schema['properties'];

    expect($props['email']['format'])->toBe('email')
        ->and($props['role']['enum'])->toBe(['admin', 'editor', 'viewer'])
        ->and($props['avatar']['format'])->toBe('binary')
        ->and($props['age']['type'])->toBe(['integer', 'null'])
        ->and($props['age']['minimum'])->toEqual(18)
        ->and($props['age']['maximum'])->toEqual(120);

    // Nested wildcard rules become an array of objects.
    expect($props['items']['type'])->toBe('array')
        ->and($props['items']['items']['type'])->toBe('object')
        ->and($props['items']['items']['properties']['sku']['type'])->toBe('string')
        ->and($props['items']['items']['properties']['qty']['minimum'])->toEqual(1);

    // Top-level required list.
    expect($schema['required'])->toContain('email', 'role', 'avatar', 'items');
});
