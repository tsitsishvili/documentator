<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\Documentator;

class PageThingResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => (int) $this->id];
    }
}

class PageThingCollection extends ResourceCollection
{
    public $collects = PageThingResource::class;
}

class ExampleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'age' => 'required|integer|min:21',
            'score' => 'nullable|integer|min:5',
        ];
    }
}

class PageSearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'term' => ['required', 'string'],
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'max:100'],
        ];
    }
}

class PageController
{
    public function index(): PageThingCollection
    {
        return new PageThingCollection(collect());
    }

    public function store(ExampleRequest $request): void
    {
        //
    }

    #[Response(status: 200, resource: PageThingResource::class, paginated: true)]
    public function search(PageSearchRequest $request): AnonymousResourceCollection
    {
        return PageThingResource::collection(collect());
    }
}

it('seeds page and per_page query params for a paginated collection response', function () {
    Route::get('api/page-things', [PageController::class, 'index']);

    $op = app(Documentator::class)->toOpenApi()['paths']['/api/page-things']['get'];
    $byName = collect($op['parameters'])->keyBy('name');

    expect($byName)->toHaveKeys(['page', 'per_page'])
        ->and($byName['page']['in'])->toBe('query')
        ->and($byName['per_page']['schema']['type'])->toBe('integer');
});

it('does not seed pagination query params a QUERY request documents as content', function () {
    Route::match(['QUERY'], 'api/page-things/search', [PageController::class, 'search']);

    $op = app(Documentator::class)->toOpenApi()['paths']['/api/page-things/search']['query'];
    $names = collect($op['parameters'] ?? [])->pluck('name');

    expect($op['requestBody']['content']['application/json']['schema']['properties'])
        ->toHaveKeys(['page', 'per_page'])
        ->and($names)->not->toContain('page')
        ->and($names)->not->toContain('per_page');
});

it('generates format-aware examples for the request body', function () {
    Route::post('api/page-things', [PageController::class, 'store']);

    $body = app(Documentator::class)->toOpenApi()['paths']['/api/page-things']['post']['requestBody'];
    $example = $body['content']['application/json']['example'];

    expect($example['email'])->toBe('user@example.com')
        ->and($example['age'])->toBe(21) // honours the min bound
        ->and($example['score'])->toBe(5); // samples the non-null side of OpenAPI 3.1 unions
});

it('omits generated examples when disabled', function () {
    config(['documentator.generate_examples' => false]);

    Route::post('api/page-things', [PageController::class, 'store']);

    $body = app(Documentator::class)->toOpenApi()['paths']['/api/page-things']['post']['requestBody'];

    expect($body['content']['application/json'])->not->toHaveKey('example');
});
