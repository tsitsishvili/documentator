<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Documentator;
use Tsitsishvili\Documentator\Support\OpenApiValidator;

class ValidationSampleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'age' => ['nullable', 'integer'],
        ];
    }
}

class ValidationSampleController
{
    public function store(ValidationSampleRequest $request): array
    {
        return ['ok' => true];
    }
}

it('emits an internally valid OpenAPI 3.1 document', function () {
    Route::post('api/validation-samples', [ValidationSampleController::class, 'store']);

    $spec = app(Documentator::class)->toOpenApi();

    expect(OpenApiValidator::validate($spec))->toBe([]);
});

it('reports invalid refs and legacy nullable schemas', function () {
    $spec = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'API', 'version' => '1.0.0'],
        'paths' => [
            '/api/broken' => [
                'get' => [
                    'parameters' => [
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'nullable' => true]],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'ok',
                            'content' => [
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/Missing']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $errors = OpenApiValidator::validate($spec);

    expect($errors)->toContain(
        'get /api/broken parameter page uses legacy nullable instead of a 3.1 null union',
        'get /api/broken response 200 application/json has an unresolved ref #/components/schemas/Missing',
    );
});
