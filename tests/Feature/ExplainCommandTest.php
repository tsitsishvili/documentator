<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

class ExplainedRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }
}

class ExplainedResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => 1];
    }
}

class ExplainedController
{
    /** Create an explained record. */
    public function store(ExplainedRequest $request): ExplainedResource
    {
        return new ExplainedResource(null);
    }
}

it('explains which strategies documented an operation', function () {
    Route::post('api/explained', [ExplainedController::class, 'store']);

    $this->artisan('documentator:explain', ['method' => 'POST', 'uri' => '/api/explained'])
        ->expectsOutputToContain('POST /api/explained')
        ->expectsOutputToContain('parameter.body.email <= ExtractFormRequestRules')
        ->expectsOutputToContain('response.201 <= ExtractResponses')
        ->assertExitCode(0);
});

it('emits machine-readable explanations', function () {
    Route::post('api/explained-json', [ExplainedController::class, 'store']);

    $exit = Artisan::call('documentator:explain', [
        'method' => 'post',
        'uri' => 'api/explained-json',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload['operation'])->toBe('POST /api/explained-json')
        ->and(collect($payload['trace'])->pluck('strategy'))->toContain('ExtractRouteMetadata', 'ExtractFormRequestRules', 'ExtractResponses');
});
