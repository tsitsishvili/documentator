<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Tsitsishvili\Documentator\Contracts\ValidationRuleTransformer;
use Tsitsishvili\Documentator\Documentator;

class ParityArticle extends Model
{
    protected $guarded = [];
}

class ParityArticleResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'articles';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'title' => $this->title,
            'published_at' => $this->published_at,
        ];
    }

    public function toRelationships(Request $request): array
    {
        return [
            'author' => ParityAuthorResource::class,
        ];
    }
}

class ParityAuthorResource extends JsonApiResource
{
    protected array $attributes = ['name'];
}

class ParityJsonApiController
{
    public function show(): ParityArticleResource
    {
        return new ParityArticleResource(new ParityArticle);
    }

    public function index()
    {
        return ParityArticleResource::collection(ParityArticle::query()->jsonPaginate());
    }
}

class ParityStoreArticleAction
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],
        ];
    }

    public function asController(): void
    {
        //
    }

    public function handle(): ParityArticleResource
    {
        return new ParityArticleResource(new ParityArticle);
    }
}

final class MoneyRuleTransformer implements ValidationRuleTransformer
{
    public function transform(string $rule, array $schema, array $rules, string $field): ?array
    {
        return $rule === 'money' ? ['type' => 'number', 'format' => 'decimal'] : null;
    }
}

class ParityInlineController
{
    public function store(Request $request): void
    {
        $request->validate([
            /**
             * Literal escaped-dot key.
             *
             * @var uuid
             *
             * @example 9f40d932-c4c0-4a36-9fb5-10d18c2a1f61
             *
             * @default 9f40d932-c4c0-4a36-9fb5-10d18c2a1f61
             */
            'user\.uuid' => ['required', Rule::exists('users', 'uuid')],

            /**
             * @ignoreParam
             */
            'internal' => ['string'],

            /**
             * @var int
             */
            'priority' => [Rule::when(true, ['integer', 'min:1'])],

            'amount' => ['required', 'money'],
        ]);

        /**
         * @query
         *
         * @var int
         *
         * @default 25
         */
        $request->input('per_page');
    }
}

class CustomArticleQueryBuilder
{
    public static function for(string $model): self
    {
        return new self;
    }

    public function allowedFilters(array $filters): self
    {
        return $this;
    }
}

class ParityQueryBuilderController
{
    public function index(): void
    {
        $this->articleQuery()->allowedFilters(['status']);
    }

    private function articleQuery(): CustomArticleQueryBuilder
    {
        return CustomArticleQueryBuilder::for(ParityArticle::class)
            ->allowedSorts(['title']);
    }
}

it('documents Laravel JsonApiResource responses, query parameters, and jsonPaginate', function () {
    Route::get('api/parity/articles/{article}', [ParityJsonApiController::class, 'show']);
    Route::get('api/parity/articles', [ParityJsonApiController::class, 'index']);

    $spec = app(Documentator::class)->toOpenApi();
    $show = $spec['paths']['/api/parity/articles/{article}']['get'];
    $index = $spec['paths']['/api/parity/articles']['get'];
    $showParameters = collect($show['parameters'])->keyBy('name');
    $indexParameters = collect($index['parameters'])->keyBy('name');

    expect($show['responses']['200']['content'])->toHaveKey('application/vnd.api+json')
        ->and($show['responses']['200']['content']['application/vnd.api+json']['schema']['properties']['data']['properties']['type']['enum'])->toBe(['articles'])
        ->and($show['responses']['200']['content']['application/vnd.api+json']['schema']['properties']['data']['properties']['attributes']['properties'])->toHaveKeys(['title', 'published_at'])
        ->and($show['responses']['200']['content']['application/vnd.api+json']['schema']['properties']['data']['properties']['relationships']['properties'])->toHaveKey('author')
        ->and($showParameters)->toHaveKeys(['include', 'fields[articles]'])
        ->and($index['responses']['200']['content']['application/vnd.api+json']['schema']['properties'])->toHaveKeys(['data', 'links', 'meta'])
        ->and($indexParameters)->toHaveKeys(['page[number]', 'page[size]']);
});

it('infers Laravel Action rules and handle return types', function () {
    Route::post('api/parity/action', [ParityStoreArticleAction::class, 'asController']);

    $operation = app(Documentator::class)->toOpenApi()['paths']['/api/parity/action']['post'];

    expect($operation['requestBody']['content']['application/json']['schema']['required'])->toContain('title')
        ->and($operation['responses']['201']['content'])->toHaveKey('application/vnd.api+json');
});

it('honors inline parameter docs, escaped dots, conditional rules, exists rules, and custom rule transformers', function () {
    config()->set('documentator.extensions.validation_rule_transformers', [MoneyRuleTransformer::class]);
    Route::post('api/parity/inline', [ParityInlineController::class, 'store']);

    $operation = app(Documentator::class)->toOpenApi()['paths']['/api/parity/inline']['post'];
    $schema = $operation['requestBody']['content']['application/json']['schema'];
    $parameters = collect($operation['parameters'])->keyBy('name');

    expect($schema['properties'])->toHaveKey('user.uuid')
        ->and($schema['properties'])->not->toHaveKey('user')
        ->and($schema['properties'])->not->toHaveKey('internal')
        ->and($schema['properties']['user.uuid']['format'])->toBe('uuid')
        ->and($schema['properties']['user.uuid']['default'])->toBe('9f40d932-c4c0-4a36-9fb5-10d18c2a1f61')
        ->and($schema['properties']['priority']['type'])->toBe('integer')
        ->and($schema['properties']['priority']['minimum'])->toBe(1.0)
        ->and($schema['properties']['amount'])->toBe(['type' => 'number', 'format' => 'decimal'])
        ->and($parameters['per_page']['in'])->toBe('query')
        ->and($parameters['per_page']['schema']['default'])->toBe(25);
});

it('follows helper methods and custom query builder subclasses for Spatie Query Builder calls', function () {
    Route::get('api/parity/query-builder', [ParityQueryBuilderController::class, 'index']);

    $parameters = collect(app(Documentator::class)
        ->toOpenApi()['paths']['/api/parity/query-builder']['get']['parameters'])->keyBy('name');

    expect($parameters['filter[status]']['schema']['type'])->toBe('string')
        ->and($parameters['sort']['schema']['enum'])->toEqualCanonicalizing(['title', '-title']);
});
