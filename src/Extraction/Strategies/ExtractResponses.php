<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Tsitsishvili\Documentator\Attributes\SchemaName;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ResponseData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\Extraction\Support\RouteActionReflection;
use Tsitsishvili\Documentator\Extraction\Support\SourceAnalyzer;
use Tsitsishvili\Documentator\OpenApi\PaginationSchema;
use Tsitsishvili\Documentator\OpenApi\ResourceSchemaExtractor;

/**
 * Infers a success response from the controller's return type when it is an
 * API Resource, including its body schema. A ResourceCollection return type is
 * wrapped in the paginator envelope. A guaranteed fallback response is added by
 * the generator, so #[Response] attributes remain free to declare real codes.
 */
final class ExtractResponses implements ExtractionStrategy
{
    public function __construct(
        private readonly ResourceSchemaExtractor $schemas,
        private readonly SourceAnalyzer $source,
    ) {}

    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        $action = RouteActionReflection::for($route, $method);

        if ($action === null) {
            return $endpoint;
        }

        // A body-bearing success is 200 unless the verb conventionally creates
        // (POST -> 201); a 204 is reserved for the empty-body fallback.
        $status = in_array('post', $endpoint->verbs(), true) && $this->infersStatus() ? 201 : 200;

        if (($collection = $this->resourceCollectionCall($action)) !== null) {
            $resource = $collection['resource'];
            $paginated = $collection['paginated'];
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $this->describe($status),
                resource: $resource,
                schema: $paginated
                    ? PaginationSchema::paginated($this->schemas->extract($resource), null)
                    : PaginationSchema::collection($this->schemas->extract($resource)),
                schemaName: $this->schemaName($resource),
            );

            if ($paginated) {
                foreach (PaginationSchema::queryParameters() as $name => $param) {
                    $endpoint->queryParameters[$name] ??= $param;
                }
            }
        }

        $returnType = $action->getReturnType();

        if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return $endpoint;
        }

        $class = $returnType->getName();

        if ($class === AnonymousResourceCollection::class) {
            return $endpoint;
        }

        if (is_subclass_of($class, ResourceCollection::class)) {
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $this->describe($status),
                resource: $class,
                schema: PaginationSchema::paginated($this->collectsSchema($class), $class),
                collection: $class,
                schemaName: $this->schemaName($class),
            );

            foreach (PaginationSchema::queryParameters() as $name => $param) {
                $endpoint->queryParameters[$name] ??= $param;
            }
        } elseif (is_subclass_of($class, JsonResource::class)) {
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $this->describe($status),
                resource: $class,
                schema: $this->schemas->extract($class),
                schemaName: $this->schemaName($class),
            );
        } elseif (is_subclass_of($class, Model::class)) {
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $this->describe($status),
                schema: $this->schemas->extractModel($class),
            );
        }

        return $endpoint;
    }

    private function infersStatus(): bool
    {
        return (bool) config('documentator.infer_status_codes', true);
    }

    private function describe(int $status): string
    {
        return $status === 201 ? 'Created' : 'Successful response';
    }

    /**
     * @return array{resource: class-string<JsonResource>, paginated: bool}|null
     */
    private function resourceCollectionCall(ReflectionFunctionAbstract $action): ?array
    {
        $return = $this->returnExpression($action);

        if (! $return instanceof Node\Expr\StaticCall
            || ! $return->class instanceof Node\Name
            || ! $return->name instanceof Node\Identifier
            || $return->name->toString() !== 'collection') {
            return null;
        }

        $resource = $return->class->toString();

        if (! is_subclass_of($resource, JsonResource::class)) {
            return null;
        }

        return [
            'resource' => $resource,
            'paginated' => isset($return->args[0]) && $this->containsPaginatorCall($return->args[0]->value),
        ];
    }

    private function returnExpression(ReflectionFunctionAbstract $action): ?Node\Expr
    {
        return $this->source->firstReturnExpression($action);
    }

    private function containsPaginatorCall(Node\Expr $expr): bool
    {
        return (new NodeFinder)->findFirst(
            $expr,
            fn (Node $node) => $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), ['paginate', 'simplePaginate', 'cursorPaginate'], true),
        ) !== null;
    }

    /**
     * The item schema a ResourceCollection collects, read from its $collects.
     *
     * @return array<string, mixed>
     */
    private function collectsSchema(string $collection): array
    {
        try {
            $collects = (new ReflectionClass($collection))->getDefaultProperties()['collects'] ?? null;
        } catch (Throwable) {
            $collects = null;
        }

        return is_string($collects) && is_subclass_of($collects, JsonResource::class)
            ? $this->schemas->extract($collects)
            : ['type' => 'object'];
    }

    private function schemaName(string $class): ?string
    {
        try {
            foreach ((new ReflectionClass($class))->getAttributes(SchemaName::class) as $attribute) {
                return $attribute->newInstance()->name;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
