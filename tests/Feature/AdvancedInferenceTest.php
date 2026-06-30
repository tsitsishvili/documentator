<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use Tsitsishvili\Documentator\Attributes\CookieParam;
use Tsitsishvili\Documentator\Attributes\HeaderParam;
use Tsitsishvili\Documentator\Attributes\OperationId;
use Tsitsishvili\Documentator\Attributes\QueryParam;
use Tsitsishvili\Documentator\Attributes\RequestMediaType;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\Attributes\ResponseHeader;
use Tsitsishvili\Documentator\Attributes\SchemaName;
use Tsitsishvili\Documentator\Attributes\Server;
use Tsitsishvili\Documentator\Attributes\TagDescription;
use Tsitsishvili\Documentator\Documentator;

#[SchemaName('PublicInvoice')]
class AdvancedInvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
        ];
    }
}

class AdvancedInferenceController
{
    private const QUERY_BUILDER_INCLUDES = ['customer', 'items'];

    public function validator(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'age' => ['nullable', 'integer', 'min:18'],
        ]);

        return response()->json(['ok' => true]);
    }

    public function accessors(Request $request): JsonResponse
    {
        $request->integer('page');
        $request->boolean('active');
        $request->date('created_after');
        $request->query('search');

        return response()->json(['ok' => true]);
    }

    #[OperationId('createInvoice')]
    #[TagDescription('Invoice operations exposed to public API clients.')]
    #[Server('https://tenant.example.com', description: 'Tenant API')]
    #[HeaderParam('X-Tenant', required: true, description: 'Tenant key')]
    #[CookieParam('preview_token', required: false)]
    #[QueryParam('include', 'list<string>')]
    #[RequestMediaType('application/vnd.api+json')]
    #[Response(201, resource: AdvancedInvoiceResource::class)]
    #[Response(202, description: 'Queued', type: 'array{id: int, status?: string, tags: list<string>, meta: array{queued_at: date-time|null}}')]
    #[ResponseHeader(201, 'X-Request-Id', description: 'Trace identifier')]
    public function attributes(Request $request): AdvancedInvoiceResource
    {
        $request->input('external_id');

        return new AdvancedInvoiceResource([]);
    }

    public function queryBuilder(): void
    {
        $filters = ['name', AllowedFilter::exact('id'), AllowedFilter::exact('published')->ignore('all')];
        $sorts = ['name', AllowedSort::field('created_at')];

        $builder = QueryBuilder::for(AdvancedInvoiceResource::class);
        $builder
            ->allowedFilters($filters)
            ->allowedSorts($sorts)
            ->defaultSort('-created_at')
            ->allowedIncludes(self::QUERY_BUILDER_INCLUDES)
            ->allowedFields(['invoices.id', 'invoices.total', 'customer.email']);
    }
}

it('infers validation rules from direct Validator::make calls', function () {
    Route::post('api/advanced/validator', [AdvancedInferenceController::class, 'validator']);

    $schema = app(Documentator::class)
        ->toOpenApi()['paths']['/api/advanced/validator']['post']['requestBody']['content']['application/json']['schema'];

    expect($schema['required'])->toContain('email')
        ->and($schema['properties']['email']['format'])->toBe('email')
        ->and($schema['properties']['age']['type'])->toBe(['integer', 'null'])
        ->and($schema['properties']['age']['minimum'])->toBe(18.0);
});

it('infers request parameters from request accessor methods', function () {
    Route::get('api/advanced/accessors', [AdvancedInferenceController::class, 'accessors']);

    $parameters = collect(app(Documentator::class)
        ->toOpenApi()['paths']['/api/advanced/accessors']['get']['parameters'])->keyBy('name');

    expect($parameters['page']['in'])->toBe('query')
        ->and($parameters['page']['schema']['type'])->toBe('integer')
        ->and($parameters['active']['schema']['type'])->toBe('boolean')
        ->and($parameters['created_after']['schema'])->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($parameters['search']['schema']['type'])->toBe('string');
});

it('emits operation, header, media type, response header, and schema name attributes', function () {
    Route::post('api/advanced/attributes', [AdvancedInferenceController::class, 'attributes']);

    $spec = app(Documentator::class)->toOpenApi();
    $operation = $spec['paths']['/api/advanced/attributes']['post'];
    $parameters = collect($operation['parameters'])->keyBy('name');

    expect($operation['operationId'])->toBe('createInvoice')
        ->and($parameters['X-Tenant']['in'])->toBe('header')
        ->and($parameters['X-Tenant']['required'])->toBeTrue()
        ->and($parameters['preview_token']['in'])->toBe('cookie')
        ->and($operation['servers'])->toBe([['url' => 'https://tenant.example.com', 'description' => 'Tenant API']])
        ->and(collect($spec['tags'])->firstWhere('name', 'Advanced Inference')['description'])->toBe('Invoice operations exposed to public API clients.')
        ->and($operation['requestBody']['content'])->toHaveKey('application/vnd.api+json')
        ->and($operation['responses']['201']['headers']['X-Request-Id']['schema']['type'])->toBe('string')
        ->and($operation['responses']['201']['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/PublicInvoice'])
        ->and($spec['components']['schemas']['PublicInvoice']['properties']['email']['type'])->toBe('string')
        ->and($parameters['include']['schema'])->toBe(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($operation['responses']['202']['content']['application/json']['schema']['properties']['id']['type'])->toBe('integer')
        ->and($operation['responses']['202']['content']['application/json']['schema']['properties']['status']['type'])->toBe('string')
        ->and($operation['responses']['202']['content']['application/json']['schema']['required'])->not->toContain('status')
        ->and($operation['responses']['202']['content']['application/json']['schema']['properties']['meta']['properties']['queued_at']['type'])->toBe(['string', 'null']);
});

it('infers Spatie Query Builder filter, sort, include, and field query parameters from literal allowed calls', function () {
    Route::get('api/advanced/query-builder', [AdvancedInferenceController::class, 'queryBuilder']);

    $parameters = collect(app(Documentator::class)
        ->toOpenApi()['paths']['/api/advanced/query-builder']['get']['parameters'])->keyBy('name');

    expect($parameters['filter[name]']['schema']['type'])->toBe('string')
        ->and($parameters['filter[id]']['schema']['type'])->toBe('integer')
        ->and($parameters['filter[published]']['description'])->toContain('Ignored values: `all`.')
        ->and($parameters['sort']['schema']['enum'])->toEqualCanonicalizing(['name', 'created_at', '-name', '-created_at'])
        ->and($parameters['sort']['description'])->toContain('Default: `created_at`.')
        ->and($parameters['include']['schema']['enum'])->toBe(['customer', 'items'])
        ->and($parameters['fields[invoices]']['schema']['enum'])->toBe(['id', 'total'])
        ->and($parameters['fields[customer]']['schema']['enum'])->toBe(['email']);
});
