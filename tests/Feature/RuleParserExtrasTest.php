<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Tsitsishvili\Documentator\Documentator;

enum Priority: int
{
    case Low = 1;
    case High = 2;
}

class ExtrasRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'password' => 'required|string|min:8|confirmed',
            'slug' => ['required', 'regex:/^[a-z0-9-]+$/'],
            'pin' => 'required|digits:4',
            'priority' => ['required', Rule::enum(Priority::class)],
        ];
    }
}

class ExtrasController
{
    public function store(ExtrasRequest $request): void
    {
        //
    }
}

it('handles confirmed, regex, digits and int-backed enums', function () {
    Route::post('api/extras', [ExtrasController::class, 'store']);

    $schema = app(Documentator::class)->toOpenApi()['paths']['/api/extras']['post']['requestBody']['content']['application/json']['schema'];
    $props = $schema['properties'];

    // confirmed -> a mirrored *_confirmation field.
    expect($props)->toHaveKey('password_confirmation')
        ->and($props['password_confirmation']['type'])->toBe('string')
        ->and($schema['required'])->toContain('password_confirmation');

    // regex -> JSON Schema pattern (delimiters stripped).
    expect($props['slug']['pattern'])->toBe('^[a-z0-9-]+$');

    // digits -> integer.
    expect($props['pin']['type'])->toBe('integer');

    // int-backed enum -> integer type with integer enum values.
    expect($props['priority']['type'])->toBe('integer')
        ->and($props['priority']['enum'])->toBe([1, 2]);
});
