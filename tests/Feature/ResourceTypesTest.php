<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Attributes\UseResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\Attributes\UsesModel;
use Tsitsishvili\Documentator\Documentator;

class Widget extends Model
{
    protected $casts = [
        'price' => 'float',
        'active' => 'boolean',
        'published_at' => 'datetime',
        'tags' => 'array',
    ];
}

class TagResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => (int) $this->id, 'label' => $this->label];
    }
}

#[UsesModel(Widget::class)]
class WidgetResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'price' => $this->price,
            'active' => $this->active,
            'published_at' => $this->published_at,
            'tags' => $this->tags,
            'name' => $this->name,
            'tag_list' => TagResource::collection($this->whenLoaded('tags')),
        ];
    }
}

class WidgetCollection extends ResourceCollection
{
    public $collects = WidgetResource::class;
}

class LinklessWidgetCollection extends ResourceCollection
{
    public $collects = WidgetResource::class;

    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        unset($default['links'], $default['meta']['links']);

        return $default;
    }
}

class WidgetShowController
{
    public function show(): WidgetResource
    {
        return new WidgetResource(null);
    }
}

class WidgetIndexController
{
    public function index(): WidgetCollection
    {
        return new WidgetCollection(collect());
    }

    public function linkless(): LinklessWidgetCollection
    {
        return new LinklessWidgetCollection(collect());
    }

    #[Response(200, resource: WidgetResource::class, paginated: true)]
    public function paged(): void
    {
        //
    }

    #[Response(200, resource: WidgetResource::class, paginated: true)]
    public function linklessPaged(): LinklessWidgetCollection
    {
        return new LinklessWidgetCollection(collect());
    }
}

class GadgetResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => (int) $this->id, 'label' => (string) $this->label];
    }
}

#[UseResource(GadgetResource::class)]
class Gadget extends Model
{
    public function parts(): HasMany
    {
        return $this->hasMany(Doohickey::class);
    }
}

class DoohickeyResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['serial' => (string) $this->serial];
    }
}

class DoohickeyResourceCollection extends ResourceCollection
{
    public $collects = DoohickeyResource::class;
}

class Doohickey extends Model
{
    // Stands in for Laravel's App\Models -> App\Http\Resources naming convention.
    public static function guessResourceName(): array
    {
        return [DoohickeyResource::class];
    }
}

class UntypedResourceController
{
    public function newed(Gadget $gadget)
    {
        return new GadgetResource($gadget);
    }

    public function made(Gadget $gadget)
    {
        return GadgetResource::make($gadget);
    }

    public function toResource(Gadget $gadget)
    {
        return $gadget->toResource();
    }

    public function toResourceTyped(Gadget $gadget): JsonResource
    {
        return $gadget->toResource();
    }

    public function explicit(Gadget $gadget)
    {
        return $gadget->toResource(WidgetResource::class);
    }

    public function found(int $id)
    {
        return Gadget::findOrFail($id)->toResource();
    }

    public function guessed(Doohickey $doohickey)
    {
        return $doohickey->toResource();
    }

    public function index()
    {
        return Gadget::query()->paginate()->toResourceCollection();
    }

    public function guessedIndex()
    {
        return Doohickey::all()->toResourceCollection();
    }

    public function unknown(Request $request)
    {
        return $request->user()->toResource();
    }

    public function related(Gadget $gadget)
    {
        return $gadget->parts()->paginate()->toResourceCollection();
    }
}

function widgetSchema(string $uri): array
{
    return app(Documentator::class)->toOpenApi()['paths'][$uri]['get']['responses']['200']['content']['application/json']['schema'];
}

function widgetSchemaProps(string $uri): array
{
    $schema = widgetSchema($uri);

    return app(Documentator::class)->toOpenApi()['components']['schemas'][basename($schema['$ref'] ?? '')]['properties']
        ?? $schema['properties'];
}

$toResourceMissing = ! method_exists(Model::class, 'toResource');

it('documents untyped new and make() resource returns', function () {
    Route::get('api/gadgets/{gadget}/newed', [UntypedResourceController::class, 'newed']);
    Route::get('api/gadgets/{gadget}/made', [UntypedResourceController::class, 'made']);

    expect(widgetSchemaProps('/api/gadgets/{gadget}/newed')['label']['type'])->toBe('string')
        ->and(widgetSchemaProps('/api/gadgets/{gadget}/made')['label']['type'])->toBe('string');
});

it('resolves toResource() from #[UseResource], an explicit class, and a static model chain', function () {
    Route::get('api/gadgets/{gadget}/to-resource', [UntypedResourceController::class, 'toResource']);
    Route::get('api/gadgets/{gadget}/typed', [UntypedResourceController::class, 'toResourceTyped']);
    Route::get('api/gadgets/{gadget}/explicit', [UntypedResourceController::class, 'explicit']);
    Route::get('api/gadgets/{id}/found', [UntypedResourceController::class, 'found']);

    expect(widgetSchemaProps('/api/gadgets/{gadget}/to-resource')['label']['type'])->toBe('string')
        ->and(widgetSchemaProps('/api/gadgets/{gadget}/typed')['label']['type'])->toBe('string')
        ->and(widgetSchemaProps('/api/gadgets/{gadget}/explicit')['price']['type'])->toBe('number')
        ->and(widgetSchemaProps('/api/gadgets/{id}/found')['label']['type'])->toBe('string');
})->skip($toResourceMissing, 'toResource() requires a newer Laravel release.');

it('falls back to the model guessed resource name for toResource()', function () {
    Route::get('api/doohickeys/{doohickey}', [UntypedResourceController::class, 'guessed']);

    expect(widgetSchemaProps('/api/doohickeys/{doohickey}')['serial']['type'])->toBe('string');
})->skip($toResourceMissing, 'toResource() requires a newer Laravel release.');

it('documents toResourceCollection() and paginates only paginated chains', function () {
    Route::get('api/gadgets', [UntypedResourceController::class, 'index']);
    Route::get('api/doohickeys', [UntypedResourceController::class, 'guessedIndex']);

    $paged = widgetSchema('/api/gadgets');
    $plain = widgetSchema('/api/doohickeys');
    $params = array_column(app(Documentator::class)->toOpenApi()['paths']['/api/gadgets']['get']['parameters'] ?? [], 'name');

    expect($paged['properties']['data']['items']['properties']['label']['type'])->toBe('string')
        ->and($paged['properties']['meta']['properties']['total']['type'])->toBe('integer')
        ->and($params)->toContain('page')
        ->and($plain['properties']['data']['items']['properties']['serial']['type'])->toBe('string')
        ->and($plain['properties'])->not->toHaveKey('meta');
})->skip($toResourceMissing, 'toResourceCollection() requires a newer Laravel release.');

it('leaves an unresolvable or relationship-changed receiver as a generic response', function () {
    Route::get('api/me', [UntypedResourceController::class, 'unknown']);
    Route::get('api/gadgets/{gadget}/parts', [UntypedResourceController::class, 'related']);

    $paths = app(Documentator::class)->toOpenApi()['paths'];

    expect($paths['/api/me']['get']['responses']['200'])->not->toHaveKey('content')
        ->and($paths['/api/gadgets/{gadget}/parts']['get']['responses']['200'])->not->toHaveKey('content');
});

it('types resource fields from the model casts', function () {
    Route::get('api/widgets/{widget}', [WidgetShowController::class, 'show']);

    $props = widgetSchema('/api/widgets/{widget}')['properties'];

    expect($props['price']['type'])->toBe('number')
        ->and($props['active']['type'])->toBe('boolean')
        ->and($props['published_at'])->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($props['tags']['type'])->toBe('array')
        ->and($props['id']['type'])->toBe('integer')
        ->and($props['name']['type'])->toBe('string');
});

it('marks whenLoaded fields optional and follows the nested resource', function () {
    Route::get('api/widgets/{widget}', [WidgetShowController::class, 'show']);

    $schema = widgetSchema('/api/widgets/{widget}');
    $tagList = $schema['properties']['tag_list'];

    expect($tagList)->not->toHaveKey('nullable')
        ->and($tagList['type'])->toBe('array')
        ->and($schema['required'])->not->toContain('tag_list')
        ->and($tagList['items']['properties']['label']['type'])->toBe('string');
});

it('wraps a ResourceCollection return type in the paginator envelope', function () {
    Route::get('api/widgets', [WidgetIndexController::class, 'index']);

    $schema = widgetSchema('/api/widgets');

    expect($schema['properties']['data']['type'])->toBe('array')
        ->and($schema['properties']['data']['items']['properties']['price']['type'])->toBe('number')
        ->and($schema['properties']['meta']['properties']['total']['type'])->toBe('integer')
        ->and($schema['properties']['links'])->toHaveKey('properties');
});

it('honours ResourceCollection paginationInformation when inferring the paginator envelope', function () {
    Route::get('api/widgets/linkless', [WidgetIndexController::class, 'linkless']);

    $schema = widgetSchema('/api/widgets/linkless');

    expect($schema['properties'])->not->toHaveKey('links')
        ->and($schema['properties']['meta']['properties'])->not->toHaveKey('links')
        ->and($schema['properties']['meta']['properties']['total']['type'])->toBe('integer');
});

it('wraps a #[Response(paginated: true)] resource in the paginator envelope', function () {
    Route::get('api/widgets/paged', [WidgetIndexController::class, 'paged']);

    $schema = widgetSchema('/api/widgets/paged');

    expect($schema['properties']['data']['items']['properties']['price']['type'])->toBe('number')
        ->and($schema['properties']['meta']['properties']['current_page']['type'])->toBe('integer');
});

it('honours ResourceCollection paginationInformation for paginated response attributes', function () {
    Route::get('api/widgets/linkless-paged', [WidgetIndexController::class, 'linklessPaged']);

    $schema = widgetSchema('/api/widgets/linkless-paged');

    expect($schema['properties'])->not->toHaveKey('links')
        ->and($schema['properties']['meta']['properties'])->not->toHaveKey('links')
        ->and($schema['properties']['data']['items']['properties']['price']['type'])->toBe('number');
});
