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

    public bool $introspectable = false;

    public ?string $routeName = null;

    public ?string $summary = null;

    public ?string $description = null;

    public ?string $operationId = null;

    public ?string $group = null;

    public ?string $groupVersion = null;

    public ?string $groupDescription = null;

    /** @var array<string, ParameterData> */
    public array $pathParameters = [];

    /** @var array<string, ParameterData> */
    public array $queryParameters = [];

    /** @var array<string, ParameterData> */
    public array $headerParameters = [];

    /** @var array<string, ParameterData> */
    public array $cookieParameters = [];

    /** @var array<string, ParameterData> */
    public array $bodyParameters = [];

    public ?string $requestMediaType = null;

    /** @var array<int, array<string, string>> */
    public array $servers = [];

    /** @var array<int, ResponseData> keyed by status code */
    public array $responses = [];

    public bool $authenticated = false;

    public ?string $securityScheme = null;

    /** @var array<int, string> */
    public array $securityScopes = [];

    public bool $hidden = false;

    public bool $deprecated = false;

    /**
     * Ordered, internal trace of which extraction strategy produced or
     * overrode each documented facet. This is intentionally not emitted into
     * the OpenAPI document; it powers the documentator:explain command.
     *
     * @var array<int, array{field: string, strategy: string, effect: string}>
     */
    public array $provenance = [];

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
        if ($this->operationId !== null) {
            return $this->operationId;
        }

        $versionPrefix = $this->groupVersion !== null
            ? Str::studly($this->groupVersion).'_'
            : '';

        if ($this->controller && $this->method) {
            return Str::camel($versionPrefix.class_basename($this->controller).'_'.$this->method);
        }

        return Str::camel($versionPrefix.implode('_', $this->verbs()).'_'.str_replace(['/', '{', '}'], '_', $this->uri));
    }
}
