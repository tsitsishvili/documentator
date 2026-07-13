<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
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

function widgetSchema(string $uri): array
{
    return app(Documentator::class)->toOpenApi()['paths'][$uri]['get']['responses']['200']['content']['application/json']['schema'];
}

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
