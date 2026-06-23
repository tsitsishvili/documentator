<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use Tsitsishvili\Documentator\Attributes\Authenticated;
use Tsitsishvili\Documentator\Attributes\BodyParam;
use Tsitsishvili\Documentator\Attributes\Deprecated;
use Tsitsishvili\Documentator\Attributes\Description;
use Tsitsishvili\Documentator\Attributes\Group;
use Tsitsishvili\Documentator\Attributes\Hidden;
use Tsitsishvili\Documentator\Attributes\PathParam;
use Tsitsishvili\Documentator\Attributes\QueryParam;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\Attributes\Summary;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ParameterData;
use Tsitsishvili\Documentator\Data\ResponseData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\OpenApi\PaginationSchema;
use Tsitsishvili\Documentator\OpenApi\ResourceSchemaExtractor;

/**
 * Applies explicit PHP attribute overrides. Runs LAST in the pipeline, so any
 * value declared here replaces what inference produced. Class-level Group /
 * Authenticated / Hidden act as defaults; method-level attributes win.
 */
final class ExtractAttributes implements ExtractionStrategy
{
    public function __construct(private readonly ResourceSchemaExtractor $schemas) {}

    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        if ($method === null) {
            return $endpoint;
        }

        $this->applyClassLevel($endpoint, $method);
        $this->applyMethodLevel($endpoint, $method);

        return $endpoint;
    }

    private function applyClassLevel(EndpointData $endpoint, ReflectionMethod $method): void
    {
        $class = $method->getDeclaringClass();

        foreach ($class->getAttributes(Group::class) as $attribute) {
            $endpoint->group = $attribute->newInstance()->name;
        }

        foreach ($class->getAttributes(Authenticated::class) as $attribute) {
            $endpoint->authenticated = true;
            $endpoint->securityScheme = $attribute->newInstance()->scheme;
        }

        if ($class->getAttributes(Hidden::class) !== []) {
            $endpoint->hidden = true;
        }

        if ($class->getAttributes(Deprecated::class) !== [] || $this->isNativeDeprecated($class)) {
            $endpoint->deprecated = true;
        }
    }

    private function applyMethodLevel(EndpointData $endpoint, ReflectionMethod $method): void
    {
        foreach ($method->getAttributes(Summary::class) as $attribute) {
            $endpoint->summary = $attribute->newInstance()->text;
        }

        foreach ($method->getAttributes(Description::class) as $attribute) {
            $endpoint->description = $attribute->newInstance()->text;
        }

        foreach ($method->getAttributes(Group::class) as $attribute) {
            $endpoint->group = $attribute->newInstance()->name;
        }

        foreach ($method->getAttributes(Authenticated::class) as $attribute) {
            $endpoint->authenticated = true;
            $endpoint->securityScheme = $attribute->newInstance()->scheme;
        }

        if ($method->getAttributes(Hidden::class) !== []) {
            $endpoint->hidden = true;
        }

        if ($method->getAttributes(Deprecated::class) !== [] || $this->isNativeDeprecated($method)) {
            $endpoint->deprecated = true;
        }

        foreach ($method->getAttributes(PathParam::class) as $attribute) {
            $param = $attribute->newInstance();
            $endpoint->pathParameters[$param->name] = new ParameterData(
                name: $param->name,
                type: $param->type,
                required: true,
                description: $param->description,
                example: $param->example,
            );
        }

        foreach ($method->getAttributes(QueryParam::class) as $attribute) {
            $param = $attribute->newInstance();
            $endpoint->queryParameters[$param->name] = new ParameterData(
                name: $param->name,
                type: $param->type,
                required: $param->required,
                description: $param->description,
                example: $param->example,
            );
        }

        foreach ($method->getAttributes(BodyParam::class) as $attribute) {
            $param = $attribute->newInstance();
            $endpoint->bodyParameters[$param->name] = new ParameterData(
                name: $param->name,
                type: $param->type,
                required: $param->required,
                description: $param->description,
                example: $param->example,
            );
        }

        foreach ($method->getAttributes(Response::class) as $attribute) {
            $response = $attribute->newInstance();
            $endpoint->responses[$response->status] = new ResponseData(
                status: $response->status,
                description: $response->description,
                example: $response->example,
                resource: $response->resource,
                schema: $this->responseSchema($response),
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function responseSchema(Response $response): ?array
    {
        if ($response->resource === null) {
            return null;
        }

        $item = $this->schemas->extract($response->resource);

        return match (true) {
            $response->paginated => PaginationSchema::paginated($item),
            $response->collection => PaginationSchema::collection($item),
            default => $item,
        };
    }

    private function isNativeDeprecated(ReflectionClass|ReflectionMethod $reflector): bool
    {
        // Honour PHP 8.4's native #[\Deprecated] attribute too.
        return PHP_VERSION_ID >= 80400 && $reflector->getAttributes('Deprecated') !== [];
    }
}
