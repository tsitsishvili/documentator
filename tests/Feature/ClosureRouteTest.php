<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Attributes\Description;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\Attributes\Summary;
use Tsitsishvili\Documentator\Documentator;

class ClosureRouteWidget extends Model {}

class ClosureRouteWidgetResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'active' => $this->active,
        ];
    }
}

class ClosureRouteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

it('infers FormRequests, model bindings, return types, and attributes from closure routes', function () {
    Route::post(
        'api/closure-widgets/{widget}',
        #[Summary('Store closure widget')]
        #[Description('Creates through an anonymous route action.')]
        #[Response(202, resource: ClosureRouteWidgetResource::class)]
        function (ClosureRouteRequest $request, ClosureRouteWidget $widget): ClosureRouteWidgetResource {
            return new ClosureRouteWidgetResource($widget);
        },
    )->whereNumber('widget');

    $spec = app(Documentator::class)->toOpenApi();
    $operation = $spec['paths']['/api/closure-widgets/{widget}']['post'];
    $pathParam = collect($operation['parameters'])->firstWhere('name', 'widget');
    $bodySchema = $operation['requestBody']['content']['application/json']['schema'];
    $responseSchema = $operation['responses']['202']['content']['application/json']['schema'];
    $responseProps = $spec['components']['schemas']['ClosureRouteWidgetResource']['properties'];

    expect($operation['summary'])->toBe('Store closure widget')
        ->and($operation['description'])->toBe('Creates through an anonymous route action.')
        ->and($pathParam['schema']['type'])->toBe('integer')
        ->and($bodySchema['required'])->toContain('name')
        ->and($bodySchema['properties']['name']['maxLength'])->toBe(80)
        ->and($bodySchema['properties']['quantity']['type'])->toBe(['integer', 'null'])
        ->and($bodySchema['properties']['quantity']['minimum'])->toBe(1.0)
        ->and($operation['responses'])->toHaveKeys(['202', '404', '422'])
        ->and($responseSchema)->toBe(['$ref' => '#/components/schemas/ClosureRouteWidgetResource'])
        ->and($responseProps)->toHaveKeys(['id', 'name', 'active']);
});

it('infers inline validation and JSON responses from closure route bodies', function () {
    Route::get(
        'api/closure-widgets',
        /**
         * Search closure widgets.
         *
         * Uses inline query validation.
         */
        function (Request $request): JsonResponse {
            $request->validate([
                'q' => ['required', 'string', 'min:3'],
                'page' => ['nullable', 'integer', 'min:1'],
            ]);

            return response()->json([
                'ok' => true,
                'email' => 'ada@example.com',
            ], 202);
        },
    );

    $operation = app(Documentator::class)->toOpenApi()['paths']['/api/closure-widgets']['get'];
    $parameters = collect($operation['parameters'])->keyBy('name');
    $responseProps = $operation['responses']['202']['content']['application/json']['schema']['properties'];

    expect($operation['summary'])->toBe('Search closure widgets.')
        ->and($operation['description'])->toBe('Uses inline query validation.')
        ->and($operation)->not->toHaveKey('requestBody')
        ->and($parameters['q']['required'])->toBeTrue()
        ->and($parameters['q']['schema']['minLength'])->toBe(3)
        ->and($parameters['page']['schema']['type'])->toBe(['integer', 'null'])
        ->and($operation['responses'])->toHaveKeys(['202', '422'])
        ->and($responseProps['ok']['type'])->toBe('boolean')
        ->and($responseProps['email'])->toBe(['type' => 'string', 'format' => 'email']);
});

it('infers inline JSON responses from arrow closure routes', function () {
    Route::get(
        'api/closure-status',
        #[Summary('Closure status')]
        fn (): JsonResponse => response()->json(['ready' => true], 202),
    );

    $operation = app(Documentator::class)->toOpenApi()['paths']['/api/closure-status']['get'];

    expect($operation['summary'])->toBe('Closure status')
        ->and($operation['responses']['202']['content']['application/json']['schema']['properties']['ready']['type'])->toBe('boolean');
});
