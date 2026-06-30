<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Tsitsishvili\Documentator\Attributes\Authenticated;
use Tsitsishvili\Documentator\Attributes\BodyParam;
use Tsitsishvili\Documentator\Attributes\CookieParam;
use Tsitsishvili\Documentator\Attributes\Deprecated;
use Tsitsishvili\Documentator\Attributes\Description;
use Tsitsishvili\Documentator\Attributes\Group;
use Tsitsishvili\Documentator\Attributes\HeaderParam;
use Tsitsishvili\Documentator\Attributes\Hidden;
use Tsitsishvili\Documentator\Attributes\OperationId;
use Tsitsishvili\Documentator\Attributes\PathParam;
use Tsitsishvili\Documentator\Attributes\QueryParam;
use Tsitsishvili\Documentator\Attributes\RequestMediaType;
use Tsitsishvili\Documentator\Attributes\Response;
use Tsitsishvili\Documentator\Attributes\ResponseHeader;
use Tsitsishvili\Documentator\Attributes\SchemaName;
use Tsitsishvili\Documentator\Attributes\Server;
use Tsitsishvili\Documentator\Attributes\Summary;
use Tsitsishvili\Documentator\Attributes\TagDescription;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ParameterData;
use Tsitsishvili\Documentator\Data\ResponseData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\Extraction\Support\RouteActionReflection;
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
        $action = RouteActionReflection::for($route, $method);

        if ($action === null) {
            return $endpoint;
        }

        if ($action instanceof ReflectionMethod) {
            $this->applyClassLevel($endpoint, $action);
        }

        $this->applyMethodLevel($endpoint, $action);

        return $endpoint;
    }

    private function applyClassLevel(EndpointData $endpoint, ReflectionMethod $method): void
    {
        $class = $method->getDeclaringClass();

        foreach ($class->getAttributes(Group::class) as $attribute) {
            $group = $attribute->newInstance();
            $endpoint->group = $group->name;
            $endpoint->groupVersion = $group->version;
        }

        foreach ($class->getAttributes(TagDescription::class) as $attribute) {
            $endpoint->groupDescription = $attribute->newInstance()->text;
        }

        foreach ($class->getAttributes(Server::class) as $attribute) {
            $server = $attribute->newInstance();
            $endpoint->servers[] = array_filter([
                'url' => $server->url,
                'description' => $server->description,
            ], fn ($value) => $value !== null);
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

    private function applyMethodLevel(EndpointData $endpoint, ReflectionFunctionAbstract $action): void
    {
        foreach ($action->getAttributes(Summary::class) as $attribute) {
            $endpoint->summary = $attribute->newInstance()->text;
        }

        foreach ($action->getAttributes(Description::class) as $attribute) {
            $endpoint->description = $attribute->newInstance()->text;
        }

        foreach ($action->getAttributes(OperationId::class) as $attribute) {
            $endpoint->operationId = $attribute->newInstance()->id;
        }

        foreach ($action->getAttributes(RequestMediaType::class) as $attribute) {
            $endpoint->requestMediaType = $attribute->newInstance()->mediaType;
        }

        foreach ($action->getAttributes(Group::class) as $attribute) {
            $group = $attribute->newInstance();
            $endpoint->group = $group->name;
            $endpoint->groupVersion = $group->version;
        }

        foreach ($action->getAttributes(TagDescription::class) as $attribute) {
            $endpoint->groupDescription = $attribute->newInstance()->text;
        }

        foreach ($action->getAttributes(Server::class) as $attribute) {
            $server = $attribute->newInstance();
            $endpoint->servers[] = array_filter([
                'url' => $server->url,
                'description' => $server->description,
            ], fn ($value) => $value !== null);
        }

        foreach ($action->getAttributes(Authenticated::class) as $attribute) {
            $endpoint->authenticated = true;
            $endpoint->securityScheme = $attribute->newInstance()->scheme;
        }

        if ($action->getAttributes(Hidden::class) !== []) {
            $endpoint->hidden = true;
        }

        if ($action->getAttributes(Deprecated::class) !== [] || $this->isNativeDeprecated($action)) {
            $endpoint->deprecated = true;
        }

        foreach ($action->getAttributes(PathParam::class) as $attribute) {
            $param = $attribute->newInstance();
            $endpoint->pathParameters[$param->name] = new ParameterData(
                name: $param->name,
                type: $param->type,
                required: true,
                description: $param->description,
                example: $param->example,
            );
        }

        foreach ($action->getAttributes(QueryParam::class) as $attribute) {
            $param = $attribute->newInstance();
            $endpoint->queryParameters[$param->name] = new ParameterData(
                name: $param->name,
                type: $param->type,
                required: $param->required,
                description: $param->description,
                example: $param->example,
            );
        }

        foreach ($action->getAttributes(HeaderParam::class) as $attribute) {
            $param = $attribute->newInstance();
            $endpoint->headerParameters[$param->name] = new ParameterData(
                name: $param->name,
                type: $param->type,
                required: $param->required,
                description: $param->description,
                example: $param->example,
            );
        }

        foreach ($action->getAttributes(CookieParam::class) as $attribute) {
            $param = $attribute->newInstance();
            $endpoint->cookieParameters[$param->name] = new ParameterData(
                name: $param->name,
                type: $param->type,
                required: $param->required,
                description: $param->description,
                example: $param->example,
            );
        }

        foreach ($action->getAttributes(BodyParam::class) as $attribute) {
            $param = $attribute->newInstance();
            $endpoint->bodyParameters[$param->name] = new ParameterData(
                name: $param->name,
                type: $param->type,
                required: $param->required,
                description: $param->description,
                example: $param->example,
            );
        }

        foreach ($action->getAttributes(Response::class) as $attribute) {
            $response = $attribute->newInstance();
            $endpoint->responses[$response->status] = new ResponseData(
                status: $response->status,
                description: $response->description,
                example: $response->example,
                resource: $response->resource,
                type: $response->type,
                schema: $this->responseSchema($response, $action),
                collection: $this->paginatedCollection($action),
                paginationLinks: $response->paginationLinks,
                schemaName: $this->schemaName($response->resource),
            );

            if ($response->paginated) {
                foreach (PaginationSchema::queryParameters() as $name => $param) {
                    $endpoint->queryParameters[$name] ??= $param;
                }
            }
        }

        foreach ($action->getAttributes(ResponseHeader::class) as $attribute) {
            $header = $attribute->newInstance();
            $response = $endpoint->responses[$header->status] ??= new ResponseData(
                status: $header->status,
                description: $this->describe($header->status),
            );
            $response->headers[$header->name] = new ParameterData(
                name: $header->name,
                type: $header->type,
                description: $header->description,
                example: $header->example,
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function responseSchema(Response $response, ReflectionFunctionAbstract $action): ?array
    {
        if ($response->resource === null) {
            return null;
        }

        $item = $this->schemas->extract($response->resource);

        return match (true) {
            $response->paginated => PaginationSchema::paginated($item, $this->paginatedCollection($action), $response->paginationLinks),
            $response->collection => PaginationSchema::collection($item),
            default => $item,
        };
    }

    /**
     * @return class-string<ResourceCollection>|null
     */
    private function paginatedCollection(ReflectionFunctionAbstract $action): ?string
    {
        $returnType = $action->getReturnType();

        if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        $class = $returnType->getName();

        return is_subclass_of($class, ResourceCollection::class) ? $class : null;
    }

    private function isNativeDeprecated(ReflectionClass|ReflectionFunctionAbstract $reflector): bool
    {
        // Honour PHP 8.4's native #[\Deprecated] attribute too.
        return PHP_VERSION_ID >= 80400 && $reflector->getAttributes('Deprecated') !== [];
    }

    private function describe(int $status): string
    {
        return match ($status) {
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No content',
            301 => 'Moved permanently',
            302 => 'Found',
            400 => 'Bad request',
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Not found',
            422 => 'Validation error',
            500 => 'Server error',
            default => 'Response',
        };
    }

    private function schemaName(?string $class): ?string
    {
        if ($class === null || ! class_exists($class)) {
            return null;
        }

        foreach ((new ReflectionClass($class))->getAttributes(SchemaName::class) as $attribute) {
            return $attribute->newInstance()->name;
        }

        return null;
    }
}
