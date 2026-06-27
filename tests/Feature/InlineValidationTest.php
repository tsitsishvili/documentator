<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\Group;
use Tsitsishvili\Documentator\Documentator;

#[Group('Newsletter')]
class InlineNewsletterController
{
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'topics' => ['nullable', 'array'],
            'topics.*' => ['string', 'max:50'],
            'consent' => ['required', 'boolean', 'accepted'],
        ]);

        return response()->json([
            'subscribed' => true,
            'email' => $validated['email'],
            'frequency' => $validated['frequency'],
        ], HttpResponse::HTTP_ACCEPTED);
    }

    public function index(Request $request): void
    {
        request()->validate([
            'q' => 'required|string|min:3',
            'page' => 'nullable|integer|min:1',
        ]);
    }
}

it('infers body params and response schemas from inline request validation and JSON responses', function () {
    Route::post('api/newsletter/subscriptions', [InlineNewsletterController::class, 'subscribe']);

    $operation = app(Documentator::class)->toOpenApi()['paths']['/api/newsletter/subscriptions']['post'];
    $schema = $operation['requestBody']['content']['application/json']['schema'];
    $props = $schema['properties'];

    expect($props)->toHaveKeys(['email', 'name', 'frequency', 'topics', 'consent'])
        ->and($schema['required'])->toContain('email', 'frequency', 'consent')
        ->and($props['email']['format'])->toBe('email')
        ->and($props['email']['maxLength'])->toBe(255)
        ->and($props['name']['type'])->toBe(['string', 'null'])
        ->and($props['frequency']['enum'])->toBe(['daily', 'weekly', 'monthly'])
        ->and($props['topics']['type'])->toBe(['array', 'null'])
        ->and($props['topics']['items']['type'])->toBe('string')
        ->and($props['topics']['items']['maxLength'])->toBe(50)
        ->and($props['consent']['type'])->toBe('boolean')
        ->and($operation['responses'])->toHaveKeys(['202', '422']);

    $responseProps = $operation['responses']['202']['content']['application/json']['schema']['properties'];
    $validationProps = $operation['responses']['422']['content']['application/json']['schema']['properties'];

    expect($operation['responses']['202']['description'])->toBe('Accepted')
        ->and($responseProps['subscribed']['type'])->toBe('boolean')
        ->and($responseProps['email'])->toBe(['type' => 'string', 'format' => 'email'])
        ->and($responseProps['frequency']['type'])->toBe('string')
        ->and($operation['responses']['422']['description'])->toBe('Validation error')
        ->and($validationProps['message']['type'])->toBe('string')
        ->and($validationProps['errors']['additionalProperties']['items']['type'])->toBe('string');
});

it('routes GET inline validation rules to query parameters', function () {
    Route::get('api/newsletter/subscriptions', [InlineNewsletterController::class, 'index']);

    $operation = app(Documentator::class)->toOpenApi()['paths']['/api/newsletter/subscriptions']['get'];
    $parameters = collect($operation['parameters'])->keyBy('name');

    expect($operation)->not->toHaveKey('requestBody')
        ->and($parameters['q']['in'])->toBe('query')
        ->and($parameters['q']['required'])->toBeTrue()
        ->and($parameters['q']['schema']['minLength'])->toBe(3)
        ->and($parameters['page']['schema']['type'])->toBe(['integer', 'null'])
        ->and($parameters['page']['schema']['minimum'])->toBe(1.0)
        ->and($parameters['page']['schema'])->not->toHaveKey('nullable');
});
