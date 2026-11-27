<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Tsitsishvili\Documentator\Documentator;

class RuleAccuracyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'regex:/^(a|b)$/'],
            'user_id' => 'required|uuid|exists:users,id',
            'owner' => 'required|string|exists:users,id',
            'account_id' => 'required|exists:accounts,id',
            'avatar' => 'file|max:2048',
            'born' => 'date_format:Y-m-d',
            'opens' => 'date_format:H:i',
            'starts_at' => 'date_format:Y-m-d H:i:s',
            'tags' => ['required', 'list'],
            'tos' => 'accepted',
            'kind' => [Rule::in(['a,b', 'c'])],
            'address' => 'ipv6',
            // Fluent numeric rules are not in the oldest supported Laravel 12.
            ...(method_exists(Rule::class, 'numeric') ? ['amount' => [Rule::numeric()->min(1)]] : []),
        ];
    }
}

class RuleAccuracyController
{
    public function store(RuleAccuracyRequest $request): void
    {
        //
    }

    public function update(Request $request): void
    {
        $request->validate([
            'title' => [Rule::when(true, 'required|string')],
        ]);
    }
}

it('documents validation rules the way Laravel reads them', function () {
    Route::post('api/rule-accuracy', [RuleAccuracyController::class, 'store']);

    $props = app(Documentator::class)->toOpenApi()['paths']['/api/rule-accuracy']['post']['requestBody']['content']['multipart/form-data']['schema']['properties'];

    // Array rules are not split on "|", so a regex alternation survives.
    expect($props['code']['pattern'])->toBe('^(a|b)$');

    // An explicit type beats the exists:table,id integer guess.
    expect($props['user_id'])->toBe(['type' => 'string', 'format' => 'uuid'])
        ->and($props['owner']['type'])->toBe('string')
        ->and($props['account_id']['type'])->toBe('integer');

    // File size limits (kilobytes) are not string lengths.
    expect($props['avatar'])->toBe(['type' => 'string', 'format' => 'binary']);

    expect($props['born']['format'])->toBe('date')
        ->and($props['opens'])->not->toHaveKey('format')
        ->and($props['starts_at']['format'])->toBe('date-time');

    expect($props['tags']['type'])->toBe('array')
        ->and($props['tos']['type'])->toBe('boolean')
        ->and($props['kind']['enum'])->toBe(['a,b', 'c'])
        ->and($props['address']['format'])->toBe('ipv6');

    if (isset($props['amount'])) {
        expect($props['amount'])->toBe(['type' => 'number', 'minimum' => 1.0]);
    }
});

it('still splits a piped string passed to an inline Rule::when', function () {
    Route::put('api/rule-accuracy', [RuleAccuracyController::class, 'update']);

    $schema = app(Documentator::class)->toOpenApi()['paths']['/api/rule-accuracy']['put']['requestBody']['content']['application/json']['schema'];

    expect($schema['properties']['title']['type'])->toBe('string')
        ->and($schema['required'])->toBe(['title']);
});
