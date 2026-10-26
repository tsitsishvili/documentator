<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Database\Eloquent\Attributes\UseResource;
use Illuminate\Database\Eloquent\Attributes\UseResourceCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Tsitsishvili\Documentator\Attributes\SchemaName;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ParameterData;
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
            $jsonApi = $this->schemas->isJsonApiResource($resource);
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $this->describe($status),
                resource: $resource,
                schema: $jsonApi
                    ? $this->schemas->jsonApiCollection($resource, $paginated)
                    : ($paginated
                        ? PaginationSchema::paginated($this->schemas->extract($resource), null)
                        : PaginationSchema::collection($this->schemas->extract($resource))),
                mediaType: $jsonApi ? 'application/vnd.api+json' : null,
                schemaName: $this->schemaName($resource),
            );

            if ($paginated) {
                $endpoint->seedQueryParameters($collection['jsonApiPaginated']
                    ? PaginationSchema::jsonApiQueryParameters()
                    : PaginationSchema::queryParameters());
            }

            if ($jsonApi) {
                $this->addJsonApiQueryParameters($endpoint, $resource);
            }
        }

        $returnType = $action->getReturnType();
        $class = $returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin() ? $returnType->getName() : null;
        $paginated = true;

        // Untyped or base-typed actions name their resource only in the return statement.
        if (in_array($class, [null, JsonResource::class, ResourceCollection::class], true)) {
            [$class, $paginated] = $this->returnedResource($action) ?? [$class, true];
        }

        if ($class === null || $class === AnonymousResourceCollection::class) {
            return $endpoint;
        }

        if (is_subclass_of($class, ResourceCollection::class)) {
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $this->describe($status),
                resource: $class,
                schema: $paginated
                    ? PaginationSchema::paginated($this->collectsSchema($class), $class)
                    : PaginationSchema::collection($this->collectsSchema($class)),
                collection: $class,
                schemaName: $this->schemaName($class),
            );

            if ($paginated) {
                $endpoint->seedQueryParameters(PaginationSchema::queryParameters());
            }
        } elseif (is_subclass_of($class, JsonResource::class)) {
            $jsonApi = $this->schemas->isJsonApiResource($class);
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $this->describe($status),
                resource: $class,
                schema: $this->schemas->extract($class),
                mediaType: $jsonApi ? 'application/vnd.api+json' : null,
                schemaName: $this->schemaName($class),
            );

            if ($jsonApi) {
                $this->addJsonApiQueryParameters($endpoint, $class);
            }
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
     * @return array{resource: class-string<JsonResource>, paginated: bool, jsonApiPaginated: bool}|null
     */
    private function resourceCollectionCall(ReflectionFunctionAbstract $action): ?array
    {
        $return = $this->returnExpression($action);

        [$resource, $source] = match (true) {
            $return instanceof Node\Expr\StaticCall && $return->class instanceof Node\Name && $this->isCall($return, 'collection') => [$return->class->toString(), $return->args[0]->value ?? null],
            $return instanceof Node\Expr\MethodCall && $this->isCall($return, 'toResourceCollection') => [$this->collectionTarget($return, $action, 'resource'), $return->var],
            default => [null, null],
        };

        if (! is_string($resource) || ! is_subclass_of($resource, JsonResource::class)) {
            return null;
        }

        $jsonApiPaginated = $source instanceof Node\Expr && $this->containsJsonApiPaginatorCall($source);
        $paginated = $jsonApiPaginated || ($source instanceof Node\Expr && $this->containsPaginatorCall($source));

        return [
            'resource' => $resource,
            'paginated' => $paginated,
            'jsonApiPaginated' => $jsonApiPaginated,
        ];
    }

    /**
     * The Resource or ResourceCollection class an untyped return statement
     * builds — `new X(...)`, `X::make(...)`, `$model->toResource()` or a
     * `toResourceCollection()` that resolves to a collection class — and whether
     * the returned value is paginated.
     *
     * @return array{0: class-string, 1: bool}|null
     */
    private function returnedResource(ReflectionFunctionAbstract $action): ?array
    {
        $return = $this->returnExpression($action);

        $class = match (true) {
            $return instanceof Node\Expr\New_ && $return->class instanceof Node\Name => $return->class->toString(),
            $return instanceof Node\Expr\StaticCall && $return->class instanceof Node\Name && $this->isCall($return, 'make') => $return->class->toString(),
            $return instanceof Node\Expr\MethodCall && $this->isCall($return, 'toResource') => $this->classArgument($return)
                ?? $this->modelResource($this->receiverModel($return->var, $action)),
            $return instanceof Node\Expr\MethodCall && $this->isCall($return, 'toResourceCollection') => $this->collectionTarget($return, $action, 'collection'),
            default => null,
        };

        return $return instanceof Node\Expr && is_string($class) && is_subclass_of($class, JsonResource::class)
            ? [$class, $this->containsPaginatorCall($return)]
            : null;
    }

    /**
     * The class a `toResourceCollection()` call resolves to when it is of the
     * given kind: a ResourceCollection ('collection') or a Resource collected
     * one by one ('resource'). An explicit class argument is always a Resource.
     *
     * @param  'collection'|'resource'  $kind
     */
    private function collectionTarget(Node\Expr\MethodCall $call, ReflectionFunctionAbstract $action, string $kind): ?string
    {
        $target = ($class = $this->classArgument($call)) !== null
            ? ['resource', $class]
            : $this->modelCollection($this->receiverModel($call->var, $action));

        return $target !== null && $target[0] === $kind ? $target[1] : null;
    }

    private function isCall(Node\Expr\StaticCall|Node\Expr\MethodCall $call, string $name): bool
    {
        return $call->name instanceof Node\Identifier && $call->name->toString() === $name;
    }

    private function classArgument(Node\Expr\MethodCall $call): ?string
    {
        $value = $call->args[0]->value ?? null;

        return $value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name
            ? $value->class->toString()
            : null;
    }

    /**
     * The Eloquent model a call chain starts from: a typed action parameter
     * (`$order->...`) or a static model call (`Order::query()->...`).
     */
    private function receiverModel(Node\Expr $expr, ReflectionFunctionAbstract $action): ?string
    {
        $calls = [];

        while ($expr instanceof Node\Expr\MethodCall) {
            $calls[] = $expr->name instanceof Node\Identifier ? $expr->name->toString() : null;
            $expr = $expr->var;
        }

        $model = match (true) {
            $expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name => $expr->class->toString(),
            $expr instanceof Node\Expr\Variable && is_string($expr->name) => $this->parameterClass($action, $expr->name),
            default => null,
        };

        if ($model === null || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        foreach ($calls as $call) {
            // A method the app's model declares (typically a relationship) changes which model is returned.
            if ($call === null || (method_exists($model, $call)
                && ! str_starts_with((new ReflectionMethod($model, $call))->getDeclaringClass()->getName(), 'Illuminate\\'))) {
                return null;
            }
        }

        return $model;
    }

    private function parameterClass(ReflectionFunctionAbstract $action, string $name): ?string
    {
        foreach ($action->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($parameter->getName() === $name && $type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * The resource `$model->toResource()` resolves to, in Laravel's order:
     * #[UseResource], then the guessed App\Http\Resources name.
     */
    private function modelResource(?string $model): ?string
    {
        if ($model === null) {
            return null;
        }

        return $this->attributeClass($model, UseResource::class)
            ?? Arr::first($this->guessedResources($model), fn (string $resource) => class_exists($resource));
    }

    /**
     * What `toResourceCollection()` resolves to, in Laravel's order:
     * #[UseResourceCollection], #[UseResource], a guessed {Resource}Collection,
     * then a guessed {Resource} collected one by one.
     *
     * @return array{0: 'collection'|'resource', 1: class-string}|null
     */
    private function modelCollection(?string $model): ?array
    {
        if ($model === null) {
            return null;
        }

        if (($class = $this->attributeClass($model, UseResourceCollection::class)) !== null) {
            return ['collection', $class];
        }

        if (($class = $this->attributeClass($model, UseResource::class)) !== null) {
            return ['resource', $class];
        }

        $guessed = $this->guessedResources($model);

        foreach ($guessed as $resource) {
            if (class_exists($resource.'Collection')) {
                return ['collection', $resource.'Collection'];
            }
        }

        foreach ($guessed as $resource) {
            if (class_exists($resource)) {
                return ['resource', $resource];
            }
        }

        return null;
    }

    private function attributeClass(string $model, string $attribute): ?string
    {
        try {
            $class = ((new ReflectionClass($model))->getAttributes($attribute)[0] ?? null)?->newInstance()->class;
        } catch (Throwable) {
            return null;
        }

        return is_string($class) && class_exists($class) ? $class : null;
    }

    /**
     * @return array<int, string>
     */
    private function guessedResources(string $model): array
    {
        try {
            return method_exists($model, 'guessResourceName') ? array_values(array_filter($model::guessResourceName(), 'is_string')) : [];
        } catch (Throwable) {
            return [];
        }
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

    private function containsJsonApiPaginatorCall(Node\Expr $expr): bool
    {
        return (new NodeFinder)->findFirst(
            $expr,
            fn (Node $node) => $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'jsonPaginate',
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

    private function addJsonApiQueryParameters(EndpointData $endpoint, string $resource): void
    {
        $endpoint->queryParameters['include'] ??= new ParameterData(
            name: 'include',
            type: 'string',
            required: false,
            description: 'JSON:API relationship includes. Multiple values may be comma-separated.',
            schema: ['type' => 'string'],
            style: 'form',
            explode: false,
        );

        $fields = 'fields['.$this->schemas->jsonApiTypeName($resource).']';
        $endpoint->queryParameters[$fields] ??= new ParameterData(
            name: $fields,
            type: 'string',
            required: false,
            description: 'JSON:API sparse fieldset for this resource type. Multiple values may be comma-separated.',
            schema: ['type' => 'string'],
            style: 'form',
            explode: false,
        );
    }
}
