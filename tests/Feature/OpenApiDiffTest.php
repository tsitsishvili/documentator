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

it('classifies referenced constraints, nullability, headers, and scopes', function () {
    $expected = [
        'components' => [
            'schemas' => [
                'Payload' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'properties' => [
                        'name' => ['type' => ['string', 'null'], 'maxLength' => 100],
                    ],
                ],
            ],
        ],
        'paths' => [
            '/api/payload' => [
                'get' => [
                    'security' => [['oauth' => ['read']]],
                    'responses' => [
                        '200' => [
                            'headers' => ['X-Trace' => ['schema' => ['type' => 'string']]],
                            'content' => [
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/Payload']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $actual = $expected;
    $actual['components']['schemas']['Payload']['additionalProperties'] = false;
    $actual['components']['schemas']['Payload']['properties']['name'] = ['type' => 'string', 'maxLength' => 40, 'pattern' => '^[A-Z]'];
    $actual['paths']['/api/payload']['get']['security'][0]['oauth'][] = 'write';
    $actual['paths']['/api/payload']['get']['responses']['200']['headers'] = [];

    $changes = collect(OpenApiDiff::compare($expected, $actual));
    $breaking = $changes->where('severity', 'breaking')->pluck('message')->all();

    expect($breaking)->toContain(
        'security scope added for oauth',
        'response header removed: x-trace',
        'additional properties are no longer allowed',
        'schema no longer nullable',
        'maxLength constraint changed (100 -> 40)',
        'pattern constraint added',
    );
});
