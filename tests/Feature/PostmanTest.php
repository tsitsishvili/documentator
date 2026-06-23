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
        ],
    ];

    $collection = (new PostmanGenerator)->generate($openapi);

    expect($collection['info']['schema'])->toContain('v2.1.0')
        ->and($collection['item'][0]['name'])->toBe('Orders')
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
