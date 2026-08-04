<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\CookieParam;
use Tsitsishvili\Documentator\Attributes\HeaderParam;
use Tsitsishvili\Documentator\Attributes\OperationId;
use Tsitsishvili\Documentator\Attributes\PathParam;
use Tsitsishvili\Documentator\Attributes\QueryParam;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\TypeScript\TypeScriptClientGenerator;

class TypeScriptThingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
        ];
    }
}

class TypeScriptStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'tags' => ['array'],
            'tags.*' => ['string'],
        ];
    }
}

class TypeScriptClientController
{
    #[OperationId('getThing')]
    #[PathParam('thing', 'integer')]
    #[QueryParam('include', 'list<string>')]
    #[HeaderParam('X-Tenant', required: true)]
    #[CookieParam('preview_token')]
    public function show(int $thing): TypeScriptThingResource
    {
        return new TypeScriptThingResource((object) ['id' => $thing, 'name' => 'Example']);
    }

    #[OperationId('createThing')]
    public function store(TypeScriptStoreRequest $request): TypeScriptThingResource
    {
        return new TypeScriptThingResource((object) ['id' => 1, 'name' => $request->string('name')]);
    }

    #[OperationId('searchThings')]
    #[Response(200, type: 'list<array{id: int, name: string}>')]
    public function search(TypeScriptStoreRequest $request): array
    {
        return [];
    }
}

it('generates a dependency-free typed fetch client', function () {
    Route::get('api/things/{thing}', [TypeScriptClientController::class, 'show']);
    Route::post('api/things', [TypeScriptClientController::class, 'store']);
    Route::match(['QUERY'], 'api/things/search', [TypeScriptClientController::class, 'search']);

    $path = sys_get_temp_dir().'/documentator-client-'.uniqid().'.ts';

    $this->artisan('documentator:typescript', [
        'path' => $path,
        '--name' => 'ExampleApi',
    ])->assertExitCode(0);

    $source = (string) file_get_contents($path);

    expect($source)
        ->toContain(
            'export interface TypeScriptThingResource',
            'export class ExampleApi',
            'export interface GetThingRequest',
            'path: { thing: number; }',
            'query?: { include?: Array<string>; }',
            'headers?: { "X-Tenant": string; }',
            'cookies?: { preview_token?: string; }',
            'async getThing(request: GetThingRequest): Promise<GetThingResponse>',
            'async createThing(request: CreateThingRequest): Promise<CreateThingResponse>',
            'async searchThings(request: SearchThingsRequest): Promise<SearchThingsResponse>',
            '{ method: "QUERY", headers, body }',
            'private encodeBody(',
            'throw new DocumentatorApiError',
        )
        ->not->toContain('axios');

    @unlink($path);
});

it('preserves impossible JSON Schema types', function () {
    $source = (new TypeScriptClientGenerator)->generate([
        'components' => [
            'schemas' => [
                'Impossible' => false,
            ],
        ],
    ]);

    expect($source)->toContain('export type Impossible = never;');
});
