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

class ImprovementLocalizedEventController
{
    public function emit(): void
    {
        //
    }
}

class ImprovementPayloadService
{
    public function summary(): array
    {
        return [
            'id' => 1,
            'email' => 'ada@example.com',
            'active' => true,
        ];
    }

    public static function staticSummary(): array
    {
        return [
            'team_id' => 5,
            'created_at' => '2026-01-01T00:00:00Z',
        ];
    }
}

class ImprovementResponseHelperController
{
    public function helperJson(ImprovementPayloadService $service)
    {
        $payload = $service->summary();

        return response()->json($payload, 202);
    }

    public function staticService()
    {
        return ImprovementPayloadService::staticSummary();
    }

    public function plainText()
    {
        return response('accepted', 202);
    }

    public function html()
    {
        return view('welcome');
    }

    public function redirect()
    {
        return to_route('login');
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

it('infers security from configured auth middleware aliases', function () {
    config([
        'documentator.security.internal' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Internal-Key'],
        'documentator.auth_middleware' => ['internal.auth' => 'internal'],
    ]);

    Route::get('api/improvements/internal', fn () => 'ok')
        ->middleware('internal.auth');

    $operation = app(Documentator::class)->toOpenApi()['paths']['/api/improvements/internal']['get'];

    expect($operation['security'])->toBe([['internal' => []]]);
});

it('can exclude routes by middleware alias', function () {
    config(['documentator.routes.exclude_middleware' => ['internal*']]);

    Route::get('api/improvements/public', fn () => 'ok');
    Route::get('api/improvements/internal', fn () => 'ok')->middleware('internal.docs');

    $paths = app(Documentator::class)->toOpenApi()['paths'];

    expect($paths)->toHaveKey('/api/improvements/public')
        ->not->toHaveKey('/api/improvements/internal');
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

it('applies configured global path parameter metadata', function () {
    config([
        'documentator.global_path_parameters' => [
            'pathlang' => [
                'description' => 'Language code used by localized routes.',
                'schema' => ['type' => 'string', 'enum' => ['ka', 'en']],
                'example' => 'ka',
            ],
        ],
    ]);

    Route::get('api/{pathlang}/improvements/localized', [ImprovementLocalizedEventController::class, 'emit']);

    $spec = app(Documentator::class)->toOpenApi();
    $parameter = $spec['paths']['/api/{pathlang}/improvements/localized']['get']['parameters'][0];

    expect($parameter['name'])->toBe('pathlang')
        ->and($parameter['description'])->toBe('Language code used by localized routes.')
        ->and($parameter['schema'])->toBe([
            'type' => 'string',
            'enum' => ['ka', 'en'],
            'description' => 'Language code used by localized routes.',
        ])
        ->and($parameter['example'])->toBe('ka')
        ->and($parameter['x-documentator-global'])->toBeTrue()
        ->and($spec['x-documentator-global-path-parameters']['pathlang'])->toBe([
            'description' => 'Language code used by localized routes.',
            'schema' => ['type' => 'string', 'enum' => ['ka', 'en']],
            'example' => 'ka',
        ]);
});

it('can group localized routes by path while skipping configured global parameters', function () {
    config([
        'documentator.grouping.source' => 'path',
        'documentator.grouping.ignore_path_parameters' => false,
        'documentator.global_path_parameters' => [
            'pathlang' => ['grouping' => false],
        ],
    ]);

    Route::post('api/{pathlang}/event/emit', [ImprovementLocalizedEventController::class, 'emit']);

    $spec = app(Documentator::class)->toOpenApi();
    $operation = $spec['paths']['/api/{pathlang}/event/emit']['post'];

    expect($operation['tags'])->toBe(['Event'])
        ->and($spec['tags'])->toContain(['name' => 'Event']);
});

it('groups controller-less routes by path in auto mode', function () {
    Route::get('api/{pathlang}/health/check', fn () => 'ok');

    $operation = app(Documentator::class)
        ->toOpenApi()['paths']['/api/{pathlang}/health/check']['get'];

    expect($operation['tags'])->toBe(['Health']);
});

it('can split the built-in UI into configured route sections', function () {
    config([
        'documentator.routes.match' => ['api/*', 'app/*'],
        'documentator.grouping.sections' => [
            'api' => 'API',
            'app' => 'App',
        ],
    ]);

    Route::get('api/{pathlang}/user/auction/myauctions', [ImprovementLocalizedEventController::class, 'emit']);
    Route::get('app/{pathlang}/user/auction/myauctions', [ImprovementLocalizedEventController::class, 'emit']);

    $paths = app(Documentator::class)->toOpenApi()['paths'];

    expect($paths['/api/{pathlang}/user/auction/myauctions']['get']['x-documentator-section'])->toBe('API')
        ->and($paths['/app/{pathlang}/user/auction/myauctions']['get']['x-documentator-section'])->toBe('App');
});

it('infers common Laravel response helpers and service-returned arrays', function () {
    Route::get('api/improvements/helper-json', [ImprovementResponseHelperController::class, 'helperJson']);
    Route::get('api/improvements/static-service', [ImprovementResponseHelperController::class, 'staticService']);
    Route::get('api/improvements/plain-text', [ImprovementResponseHelperController::class, 'plainText']);
    Route::get('api/improvements/html', [ImprovementResponseHelperController::class, 'html']);
    Route::get('api/improvements/redirect', [ImprovementResponseHelperController::class, 'redirect'])->name('login');

    $paths = app(Documentator::class)->toOpenApi()['paths'];
    $helper = $paths['/api/improvements/helper-json']['get']['responses']['202']['content']['application/json']['schema']['properties'];
    $static = $paths['/api/improvements/static-service']['get']['responses']['200']['content']['application/json']['schema']['properties'];

    expect($helper['id']['type'])->toBe('integer')
        ->and($helper['email']['format'])->toBe('email')
        ->and($helper['active']['type'])->toBe('boolean')
        ->and($static['team_id']['type'])->toBe('integer')
        ->and($static['created_at']['format'])->toBe('date-time')
        ->and($paths['/api/improvements/plain-text']['get']['responses']['202']['content']['text/plain']['schema']['type'])->toBe('string')
        ->and($paths['/api/improvements/html']['get']['responses']['200']['content']['text/html']['schema']['type'])->toBe('string')
        ->and($paths['/api/improvements/redirect']['get']['responses']['302']['description'])->toBe('Found');
});
