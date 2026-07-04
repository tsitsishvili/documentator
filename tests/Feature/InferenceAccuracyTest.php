<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Tsitsishvili\Documentator\Documentator;

class AccuracyProduct extends Model
{
    protected $casts = [
        'price' => 'integer',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];
}

enum AccuracyStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}

class FilterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AccuracyStatus::class)],
            'sort' => Rule::in(['name', 'created_at']),
            'per_page' => 'integer|max:100',
        ];
    }
}

class AccuracyController
{
    public function index(FilterRequest $request): void
    {
        //
    }

    public function show(int $id): void
    {
        //
    }

    public function bound(AccuracyProduct $product): void
    {
        //
    }

    public function fetch(): AccuracyProduct
    {
        return new AccuracyProduct;
    }
}

it('routes GET FormRequest rules to query parameters, not a request body', function () {
    Route::get('api/things', [AccuracyController::class, 'index']);

    $op = app(Documentator::class)->toOpenApi()['paths']['/api/things']['get'];

    expect($op)->not->toHaveKey('requestBody');

    $byName = collect($op['parameters'])->keyBy('name');
    expect($byName)->toHaveKeys(['status', 'sort', 'per_page'])
        ->and($byName['status']['in'])->toBe('query')
        ->and($byName['per_page']['in'])->toBe('query');
});

it('recovers enums from Rule::enum() and Rule::in() rule objects', function () {
    Route::get('api/things', [AccuracyController::class, 'index']);

    $params = collect(
        app(Documentator::class)->toOpenApi()['paths']['/api/things']['get']['parameters']
    )->keyBy('name');

    expect($params['status']['schema']['enum'])->toBe(['active', 'archived'])
        ->and($params['sort']['schema']['enum'])->toBe(['name', 'created_at']);
});

it('types numeric and id-shaped path parameters as integer', function () {
    Route::get('api/things/{id}', [AccuracyController::class, 'show']);
    Route::get('api/widgets/{widget}', [AccuracyController::class, 'show'])->whereNumber('widget');
    Route::get('api/posts/{slug}', [AccuracyController::class, 'show']);

    $paths = app(Documentator::class)->toOpenApi()['paths'];

    $type = fn (array $op) => $op['parameters'][0]['schema']['type'];

    expect($type($paths['/api/things/{id}']['get']))->toBe('integer')
        ->and($type($paths['/api/widgets/{widget}']['get']))->toBe('integer')
        ->and($type($paths['/api/posts/{slug}']['get']))->toBe('string');
});

it('types an implicitly model-bound path parameter from the model key', function () {
    Route::get('api/products/{product}', [AccuracyController::class, 'bound']);

    $param = app(Documentator::class)->toOpenApi()['paths']['/api/products/{product}']['get']['parameters'][0];

    // AccuracyProduct uses the default integer primary key as its route key.
    expect($param['name'])->toBe('product')
        ->and($param['schema']['type'])->toBe('integer');
});

it('normalizes custom Laravel route binding fields in OpenAPI paths', function () {
    Route::get('api/products/{product:slug}', [AccuracyController::class, 'bound']);

    $paths = app(Documentator::class)->toOpenApi()['paths'];
    $param = $paths['/api/products/{product}']['get']['parameters'][0];

    expect($paths)->toHaveKey('/api/products/{product}')
        ->and($paths)->not->toHaveKey('/api/products/{product:slug}')
        ->and($param['name'])->toBe('product')
        ->and($param['schema']['type'])->toBe('string');
});

it('keeps generated operation ids unique when routes share a controller action', function () {
    Route::get('api/things/{id}', [AccuracyController::class, 'show']);
    Route::get('api/widgets/{id}', [AccuracyController::class, 'show']);

    $paths = app(Documentator::class)->toOpenApi()['paths'];

    expect($paths['/api/things/{id}']['get']['operationId'])
        ->not->toBe($paths['/api/widgets/{id}']['get']['operationId']);
});

it('infers a response schema from a Model return type using its casts', function () {
    Route::get('api/fetch', [AccuracyController::class, 'fetch']);

    $responses = app(Documentator::class)->toOpenApi()['paths']['/api/fetch']['get']['responses'];
    $props = $responses['200']['content']['application/json']['schema']['properties'];

    expect($props['price']['type'])->toBe('integer')
        ->and($props['is_active']['type'])->toBe('boolean')
        ->and($props['published_at'])->toMatchArray(['type' => 'string', 'format' => 'date-time']);
});

it('infers conventional success status codes from the verb', function () {
    Route::post('api/things', [AccuracyController::class, 'index']);
    Route::delete('api/things/{id}', [AccuracyController::class, 'show']);

    $paths = app(Documentator::class)->toOpenApi()['paths'];

    expect($paths['/api/things']['post']['responses'])->toHaveKey('201')
        ->and($paths['/api/things/{id}']['delete']['responses'])->toHaveKey('204');
});
