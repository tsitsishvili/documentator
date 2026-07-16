<?php

declare(strict_types=1);

use Tsitsishvili\Documentator\Postman\PostmanGenerator;

it('converts an OpenAPI document into a Postman v2.1 collection', function () {
    $openapi = [
        'info' => ['title' => 'Acme', 'version' => '1.0.0'],
        'servers' => [['url' => 'https://api.acme.test']],
        'paths' => [
            '/api/orders/{order}' => [
                'get' => [
                    'tags' => ['Orders'],
                    'summary' => 'Get order',
                    'parameters' => [
                        ['name' => 'order', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                        ['name' => 'expand', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ],
                    'security' => [['default' => []]],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
                'post' => [
                    'tags' => ['Orders'],
                    'summary' => 'Update order',
                    'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['note' => ['type' => 'string']]]]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
            '/api/v2/orders' => [
                'get' => [
                    'tags' => ['Orders'],
                    'x-documentator-group-version' => 'v2',
                    'summary' => 'Get v2 orders',
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $collection = (new PostmanGenerator)->generate($openapi);

    expect($collection['info']['schema'])->toContain('v2.1.0')
        ->and($collection['item'][0]['name'])->toBe('Orders')
        ->and($collection['item'][1]['name'])->toBe('Orders v2')
        ->and($collection['variable'])->toContain(['key' => 'baseUrl', 'value' => 'https://api.acme.test']);

    $get = $collection['item'][0]['item'][0];
    expect($get['request']['method'])->toBe('GET')
        ->and($get['request']['url']['raw'])->toBe('{{baseUrl}}/api/orders/:order?expand=')
        ->and($get['request']['url']['variable'][0]['key'])->toBe('order')
        ->and($get['request']['auth']['type'])->toBe('bearer');

    $post = $collection['item'][0]['item'][1];
    expect($post['request']['method'])->toBe('POST')
        ->and($post['request']['body']['mode'])->toBe('raw')
        ->and($post['request']['body']['raw'])->toContain('note');
});

it('maps OpenAPI security requirements to Postman auth', function () {
    $openapi = [
        'info' => ['title' => 'Acme'],
        'security' => [['apiKey' => []]],
        'components' => [
            'securitySchemes' => [
                'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                'basic' => ['type' => 'http', 'scheme' => 'basic'],
            ],
        ],
        'paths' => [
            '/api/inherited' => [
                'get' => [
                    'tags' => ['Auth'],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
            '/api/public' => [
                'get' => [
                    'tags' => ['Auth'],
                    'security' => [],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
            '/api/basic' => [
                'get' => [
                    'tags' => ['Auth'],
                    'security' => [['basic' => []]],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $requests = (new PostmanGenerator)->generate($openapi)['item'][0]['item'];

    expect($requests[0]['request']['auth']['type'])->toBe('apikey')
        ->and($requests[0]['request']['auth']['apikey'][0]['value'])->toBe('X-API-Key')
        ->and($requests[1]['request'])->not->toHaveKey('auth')
        ->and($requests[2]['request']['auth']['type'])->toBe('basic');
});

it('exports multipart request bodies as Postman form data', function () {
    $openapi = [
        'info' => ['title' => 'Acme'],
        'paths' => [
            '/api/uploads' => [
                'post' => [
                    'tags' => ['Uploads'],
                    'summary' => 'Upload avatar',
                    'requestBody' => [
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['avatar'],
                                    'properties' => [
                                        'avatar' => ['type' => 'string', 'format' => 'binary'],
                                        'name' => ['type' => 'string', 'example' => 'Ada'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $request = (new PostmanGenerator)->generate($openapi)['item'][0]['item'][0]['request'];
    $form = collect($request['body']['formdata'])->keyBy('key');

    expect($request['body']['mode'])->toBe('formdata')
        ->and($form['avatar']['type'])->toBe('file')
        ->and($form['name']['value'])->toBe('Ada');
});

it('exports QUERY operations with their request body', function () {
    $openapi = [
        'info' => ['title' => 'Acme'],
        'paths' => [
            '/api/search' => [
                'query' => [
                    'tags' => ['Search'],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => ['term' => ['type' => 'string']],
                                ],
                            ],
                        ],
                    ],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $request = (new PostmanGenerator)->generate($openapi)['item'][0]['item'][0]['request'];

    expect($request['method'])->toBe('QUERY')
        ->and($request['body']['mode'])->toBe('raw')
        ->and($request['body']['raw'])->toContain('term');
});
