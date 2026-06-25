<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\Documentator;

/*
 * Fixtures: real controllers/requests/models exercising the conventional error
 * responses inferred from an endpoint's shape (closure routes skip reflection).
 */
class ErrorThing extends Model {}

class StoreErrorThingRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required|string'];
    }
}

class GuardedErrorThingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return ['name' => 'required|string'];
    }
}

class OpenErrorThingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => 'required|string'];
    }
}

class ErrorThingController
{
    public function store(StoreErrorThingRequest $request): void {}

    public function guarded(GuardedErrorThingRequest $request): void {}

    public function open(OpenErrorThingRequest $request): void {}

    public function show(ErrorThing $errorThing): void {}

    public function ping(): void {}

    #[Response(422, description: 'Custom validation shape')]
    public function declared(StoreErrorThingRequest $request): void {}
}

function errorResponses(string $uri, string $verb = 'post'): array
{
    return app(Documentator::class)->toOpenApi()['paths'][$uri][$verb]['responses'];
}

it('infers a 422 with the Laravel validation body from a FormRequest', function () {
    Route::post('api/error-things', [ErrorThingController::class, 'store']);

    $responses = errorResponses('/api/error-things');

    expect($responses)->toHaveKey('422');

    $schema = $responses['422']['content']['application/json']['schema'];
    expect($schema['properties']['message']['type'])->toBe('string')
        ->and($schema['properties']['errors']['additionalProperties']['items']['type'])->toBe('string');
});

it('infers a 403 only when the FormRequest overrides authorize()', function () {
    Route::post('api/error-things', [ErrorThingController::class, 'store']);
    Route::post('api/guarded-things', [ErrorThingController::class, 'guarded']);

    expect(errorResponses('/api/error-things'))->not->toHaveKey('403')
        ->and(errorResponses('/api/guarded-things'))->toHaveKey('403');
});

it('does not infer a 403 when authorize() always returns true', function () {
    Route::post('api/open-things', [ErrorThingController::class, 'open']);

    // The override is real but can never deny, so it is not a 403 gate. The 422
    // from validation still stands.
    expect(errorResponses('/api/open-things'))->not->toHaveKey('403')
        ->and(errorResponses('/api/open-things'))->toHaveKey('422');
});

it('infers a 401 when the endpoint requires authentication', function () {
    Route::get('api/private', [ErrorThingController::class, 'ping'])->middleware('auth');
    Route::get('api/public', [ErrorThingController::class, 'ping']);

    expect(errorResponses('/api/private', 'get'))->toHaveKey('401')
        ->and(errorResponses('/api/public', 'get'))->not->toHaveKey('401');
});

it('infers a 404 when the route binds a model', function () {
    Route::get('api/error-things/{errorThing}', [ErrorThingController::class, 'show']);
    Route::get('api/ping', [ErrorThingController::class, 'ping']);

    expect(errorResponses('/api/error-things/{errorThing}', 'get'))->toHaveKey('404')
        ->and(errorResponses('/api/ping', 'get'))->not->toHaveKey('404');
});

it('still documents a success response alongside the inferred errors', function () {
    Route::post('api/error-things', [ErrorThingController::class, 'store']);

    $responses = errorResponses('/api/error-things');

    // A POST conventionally creates, so the guaranteed success is 201.
    expect($responses)->toHaveKey('201')
        ->and($responses['201']['description'])->toBe('Created');
});

it('lets an explicit #[Response] override the inferred error', function () {
    Route::post('api/error-things', [ErrorThingController::class, 'declared']);

    $responses = errorResponses('/api/error-things');

    expect($responses['422']['description'])->toBe('Custom validation shape')
        ->and($responses['422'])->not->toHaveKey('content');
});

it('emits nothing when error response inference is disabled', function () {
    config(['documentator.error_responses' => false]);

    Route::post('api/guarded-things', [ErrorThingController::class, 'guarded'])->middleware('auth');
    Route::get('api/error-things/{errorThing}', [ErrorThingController::class, 'show']);

    expect(errorResponses('/api/guarded-things'))->toHaveKeys(['201'])
        ->and(errorResponses('/api/guarded-things'))->not->toHaveKeys(['401', '403', '422'])
        ->and(errorResponses('/api/error-things/{errorThing}', 'get'))->not->toHaveKey('404');
});
