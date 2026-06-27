<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Documentator;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\OpenApi\ResourceSchemaExtractor;

class ImprovementThing extends Model {}

class ImprovementThingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

class ConditionalThingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            $this->mergeWhen(true, [
                'secret' => $this->secret,
            ]),
            'comments_count' => $this->whenCounted('comments'),
            'profile' => $this->when(true, fn () => [
                'name' => $this->profile->name,
            ]),
        ];
    }
}

class LiteralArrayThingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'statuses' => ['draft', 'published'],
            'scores' => [1, 2],
            'children' => [
                ['id' => 1, 'name' => 'Ada'],
            ],
        ];
    }
}

class ImprovementCollectionController
{
    public function plain(): AnonymousResourceCollection
    {
        return ImprovementThingResource::collection(collect());
    }

    public function paginated(): AnonymousResourceCollection
    {
        return ImprovementThingResource::collection(ImprovementThing::query()->paginate());
    }
}

class ImprovementComponentController
{
    public function first(): ImprovementThingResource
    {
        return new ImprovementThingResource(null);
    }

    public function second(): ImprovementThingResource
    {
        return new ImprovementThingResource(null);
    }
}

class ImprovementAttributeController
{
    #[Response(200, resource: ImprovementThingResource::class, paginated: true, paginationLinks: false)]
    public function linkless(): void
    {
        //
    }
}

class ImprovementCustomStrategy implements ExtractionStrategy
{
    public function __invoke(EndpointData $endpoint, LaravelRoute $route, ?ReflectionMethod $method): EndpointData
    {
        $endpoint->description = 'Injected by a custom strategy.';

        return $endpoint;
    }
}

class ImprovementOpenApiTransformer
{
    public function __invoke(array $spec): array
    {
        $spec['x-documentator-test'] = true;

        return $spec;
    }
}

it('infers anonymous resource collections as collection or paginator shapes', function () {
    Route::get('api/improvements/plain', [ImprovementCollectionController::class, 'plain']);
    Route::get('api/improvements/paginated', [ImprovementCollectionController::class, 'paginated']);

    $spec = app(Documentator::class)->toOpenApi();
    $plain = $spec['paths']['/api/improvements/plain']['get'];
    $paginated = $spec['paths']['/api/improvements/paginated']['get'];

    expect($plain['responses']['200']['content']['application/json']['schema']['properties'])
        ->toHaveKey('data')
        ->not->toHaveKey('meta')
        ->and($plain)->not->toHaveKey('parameters')
        ->and($paginated['responses']['200']['content']['application/json']['schema']['properties'])
        ->toHaveKeys(['data', 'links', 'meta'])
        ->and(collect($paginated['parameters'])->pluck('name')->all())->toContain('page', 'per_page');
});

it('moves repeated response schemas into reusable components', function () {
    Route::get('api/improvements/first', [ImprovementComponentController::class, 'first']);
    Route::get('api/improvements/second', [ImprovementComponentController::class, 'second']);

    $spec = app(Documentator::class)->toOpenApi();
    $schema = $spec['paths']['/api/improvements/first']['get']['responses']['200']['content']['application/json']['schema'];

    expect($schema)->toBe(['$ref' => '#/components/schemas/ImprovementThingResource'])
        ->and($spec['components']['schemas']['ImprovementThingResource']['properties']['name']['type'])->toBe('string');
});

it('reads merged and conditional resource fields', function () {
    Route::get('api/improvements/conditional', fn () => null);

    $schema = app(ResourceSchemaExtractor::class)->extract(ConditionalThingResource::class);
    $props = $schema['properties'];

    expect($props['secret']['nullable'])->toBeTrue()
        ->and($props['comments_count']['type'])->toBe('integer')
        ->and($props['comments_count']['nullable'])->toBeTrue()
        ->and($props['profile']['properties']['name']['type'])->toBe('string')
        ->and($props['profile']['nullable'])->toBeTrue();
});

it('infers list item schemas from literal resource arrays', function () {
    $props = app(ResourceSchemaExtractor::class)->extract(LiteralArrayThingResource::class)['properties'];

    expect($props['statuses']['items']['type'])->toBe('string')
        ->and($props['scores']['items']['type'])->toBe('integer')
        ->and($props['children']['items']['properties']['id']['type'])->toBe('integer')
        ->and($props['children']['items']['properties']['name']['type'])->toBe('string');
});

it('allows attributes to explicitly drop pagination link blocks', function () {
    Route::get('api/improvements/linkless', [ImprovementAttributeController::class, 'linkless']);

    $schema = app(Documentator::class)
        ->toOpenApi()['paths']['/api/improvements/linkless']['get']['responses']['200']['content']['application/json']['schema'];

    expect($schema['properties'])->not->toHaveKey('links')
        ->and($schema['properties']['meta']['properties'])->not->toHaveKey('links');
});

it('infers named auth schemes and ability scopes from middleware', function () {
    config(['documentator.security.sanctum' => ['type' => 'http', 'scheme' => 'bearer']]);

    Route::get('api/improvements/private', fn () => 'ok')
        ->middleware(['auth:sanctum', 'abilities:orders:read,orders:write']);

    $operation = app(Documentator::class)->toOpenApi()['paths']['/api/improvements/private']['get'];

    expect($operation['security'])->toBe([['sanctum' => ['orders:read', 'orders:write']]]);
});

it('runs configured extraction strategies and OpenAPI transformers', function () {
    config([
        'documentator.extensions.strategies' => [ImprovementCustomStrategy::class],
        'documentator.extensions.openapi_transformers' => [ImprovementOpenApiTransformer::class],
    ]);

    Route::get('api/improvements/extended', [ImprovementComponentController::class, 'first']);

    $spec = app(Documentator::class)->toOpenApi();

    expect($spec['x-documentator-test'])->toBeTrue()
        ->and($spec['paths']['/api/improvements/extended']['get']['description'])->toBe('Injected by a custom strategy.');
});
