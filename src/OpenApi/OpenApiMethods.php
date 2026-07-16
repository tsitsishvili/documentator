<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

/**
 * HTTP methods represented as first-class Path Item fields in OpenAPI 3.2.
 */
final class OpenApiMethods
{
    /** @var array<int, string> */
    public const ALL = [
        'get',
        'put',
        'post',
        'delete',
        'options',
        'head',
        'patch',
        'trace',
        'query',
    ];
}
