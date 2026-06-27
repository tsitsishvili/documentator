<?php

declare(strict_types=1);

use Tsitsishvili\Documentator\Support\OpenApiDiff;

it('reports request body, enum, array item and security drift', function () {
    $expected = [
        'paths' => [
            '/api/orders' => [
                'post' => [
                    'security' => [],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['status'],
                                    'properties' => [
                                        'status' => ['type' => 'string', 'enum' => ['draft', 'paid']],
                                        'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'ok',
                            'content' => [
                                'application/json' => ['schema' => ['type' => 'object']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $actual = $expected;
    $actual['paths']['/api/orders']['post']['security'] = [['default' => []]];
    $actual['paths']['/api/orders']['post']['requestBody']['required'] = true;
    $actual['paths']['/api/orders']['post']['requestBody']['content']['application/json']['schema']['required'][] = 'ids';
    $actual['paths']['/api/orders']['post']['requestBody']['content']['application/json']['schema']['properties']['status']['enum'] = ['paid'];
    $actual['paths']['/api/orders']['post']['requestBody']['content']['application/json']['schema']['properties']['ids']['items']['type'] = 'string';

    $messages = collect(OpenApiDiff::compare($expected, $actual))->pluck('message')->all();

    expect($messages)->toContain(
        'security requirement added',
        'request body became required',
        'property became required: ids',
        'enum value removed',
        'schema type changed (integer -> string)',
    );
});
