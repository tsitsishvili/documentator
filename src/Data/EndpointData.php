<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Data;

use Illuminate\Support\Str;

/**
 * Mutable accumulator describing one endpoint. Each extraction strategy reads
 * and enriches the same instance; later strategies (attributes) override the
 * values inferred by earlier ones.
 */
final class EndpointData
{
    /** @var array<int, string> */
    public array $httpMethods = [];

    public string $uri = '';

    public ?string $controller = null;

    public ?string $method = null;

    public ?string $routeName = null;

    public ?string $summary = null;

    public ?string $description = null;

    public ?string $group = null;

    public ?string $groupVersion = null;

    /** @var array<string, ParameterData> */
    public array $pathParameters = [];

    /** @var array<string, ParameterData> */
    public array $queryParameters = [];

    /** @var array<string, ParameterData> */
    public array $bodyParameters = [];

    /** @var array<int, ResponseData> keyed by status code */
    public array $responses = [];

    public bool $authenticated = false;

    public ?string $securityScheme = null;

    /** @var array<int, string> */
    public array $securityScopes = [];

    public bool $hidden = false;

    public bool $deprecated = false;

    /**
     * The non-HEAD/OPTIONS HTTP verbs OpenAPI should emit operations for.
     *
     * @return array<int, string>
     */
    public function verbs(): array
    {
        return array_values(array_filter(
            array_map('strtolower', $this->httpMethods),
            fn (string $verb) => ! in_array($verb, ['head', 'options'], true),
        ));
    }

    public function operationId(): string
    {
        $versionPrefix = $this->groupVersion !== null
            ? Str::studly($this->groupVersion).'_'
            : '';

        if ($this->controller && $this->method) {
            return Str::camel($versionPrefix.class_basename($this->controller).'_'.$this->method);
        }

        return Str::camel($versionPrefix.implode('_', $this->verbs()).'_'.str_replace(['/', '{', '}'], '_', $this->uri));
    }
}
