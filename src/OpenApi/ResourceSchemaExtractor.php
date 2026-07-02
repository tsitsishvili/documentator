<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use Tsitsishvili\Documentator\Attributes\UsesModel;
use Tsitsishvili\Documentator\Extraction\Support\SourceAnalyzer;

/**
 * Recovers an OpenAPI object schema from an API Resource by statically parsing
 * the array returned from its toArray(). Field names come straight from the
 * source; nested resources are followed; types are inferred from casts, literals
 * and the wrapped model's $casts, falling back to the field name. Conditional
 * fields (whenLoaded/when) are marked nullable. Anything it can't read degrades
 * to a plain `{type: object}` rather than failing.
 */
final class ResourceSchemaExtractor
{
    private const MAX_DEPTH = 4;

    private const OBJECT = ['type' => 'object'];

    private const CONDITIONALS = [
        'when',
        'whenloaded',
        'whennotnull',
        'whenappended',
        'whenpivotloaded',
        'whenpivotloadedas',
        'whenhas',
        'whencounted',
        'whenaggregated',
        'whenexistsloaded',
        'mergewhen',
    ];

    /** @var array<string, string> casts of the model wrapped by the resource currently being parsed */
    private array $currentCasts = [];

    /** @var array<string, array<string, mixed>> @property doc types of that model */
    private array $currentDocProps = [];

    public function __construct(private readonly SourceAnalyzer $source) {}

    /**
     * @return array<string, mixed> OpenAPI schema (always at least an object)
     */
    public function extract(string $resourceClass, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH || ! is_subclass_of($resourceClass, JsonResource::class)) {
            return self::OBJECT;
        }

        if ($this->isJsonApiResource($resourceClass)) {
            return $this->jsonApiSchema($resourceClass, $depth);
        }

        $method = new ReflectionMethod($resourceClass, 'toArray');

        if (in_array($method->getDeclaringClass()->getName(), [JsonResource::class, ResourceCollection::class], true)) {
            return self::OBJECT;
        }

        $methodNode = $this->source->methodNode($method);

        $return = $methodNode === null ? null : (new NodeFinder)->findFirst(
            $methodNode,
            fn (Node $node) => $node instanceof Node\Stmt\Return_ && $node->expr instanceof Node\Expr\Array_,
        );

        if (! $return instanceof Node\Stmt\Return_ || ! $return->expr instanceof Node\Expr\Array_) {
            return self::OBJECT;
        }

        $model = $this->resolveModel($resourceClass);
        $previousCasts = $this->currentCasts;
        $previousDoc = $this->currentDocProps;
        $this->currentCasts = $model !== null ? $this->modelCasts($model) : [];
        $this->currentDocProps = $model !== null ? $this->modelDocProps($model) : [];
        $properties = $this->properties($return->expr, $depth);
        $this->currentCasts = $previousCasts;
        $this->currentDocProps = $previousDoc;

        return $properties === [] ? self::OBJECT : ['type' => 'object', 'properties' => $properties];
    }

    /**
     * Recover a schema from an Eloquent model directly (for controllers that
     * return a model rather than a Resource): its `@property` docblock gives the
     * column list (typically from laravel-ide-helper) and `$casts` refine the
     * types. Degrades to a plain object when neither is available.
     *
     * @return array<string, mixed>
     */
    public function extractModel(string $modelClass): array
    {
        if (! is_subclass_of($modelClass, Model::class)) {
            return self::OBJECT;
        }

        $properties = $this->modelDocProps($modelClass);

        $previousCasts = $this->currentCasts;
        $this->currentCasts = $this->modelCasts($modelClass);
        foreach (array_keys($this->currentCasts) as $name) {
            if (($schema = $this->castSchema($name)) !== null) {
                $properties[$name] = $schema;
            }
        }
        $this->currentCasts = $previousCasts;

        return $properties === [] ? self::OBJECT : ['type' => 'object', 'properties' => $properties];
    }

    public function isJsonApiResource(string $resourceClass): bool
    {
        return class_exists(JsonApiResource::class) && is_subclass_of($resourceClass, JsonApiResource::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonApiCollection(string $resourceClass, bool $paginated = false): array
    {
        $resourceObject = $this->jsonApiResourceObject($resourceClass, 0);
        $schema = [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => $resourceObject],
            ],
        ];

        if ($paginated) {
            $schema['properties']['links'] = [
                'type' => 'object',
                'properties' => [
                    'first' => ['type' => 'string', 'nullable' => true],
                    'last' => ['type' => 'string', 'nullable' => true],
                    'prev' => ['type' => 'string', 'nullable' => true],
                    'next' => ['type' => 'string', 'nullable' => true],
                ],
            ];
            $schema['properties']['meta'] = [
                'type' => 'object',
                'properties' => [
                    'current_page' => ['type' => 'integer'],
                    'from' => ['type' => 'integer', 'nullable' => true],
                    'last_page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'to' => ['type' => 'integer', 'nullable' => true],
                    'total' => ['type' => 'integer'],
                ],
            ];
        }

        return $schema;
    }

    public function jsonApiTypeName(string $resourceClass): string
    {
        return $this->jsonApiType($resourceClass);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function properties(Node\Expr\Array_ $array, int $depth): array
    {
        $properties = [];

        foreach ($array->items as $item) {
            if (! $item instanceof Node\ArrayItem) {
                continue;
            }

            if (! $item->key instanceof Scalar\String_) {
                $properties = array_replace($properties, $this->mergedProperties($item->value, $depth));

                continue;
            }

            $name = $item->key->value;
            $value = $item->value;

            if ($this->isConditional($value) && $value instanceof Node\Expr\MethodCall) {
                $schema = $this->conditionalNested($value, $depth) ?? $this->inferType($value, $depth) ?? $this->fallback($name);
            } else {
                $schema = $this->inferType($value, $depth) ?? $this->fallback($name);
            }

            // A whenLoaded()/when() anywhere in the value means the field is optional.
            if ($this->hasConditional($value)) {
                $schema['nullable'] = true;
            }

            $properties[$name] = $schema;
        }

        return $properties;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonApiSchema(string $resourceClass, int $depth): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => $this->jsonApiResourceObject($resourceClass, $depth),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonApiResourceObject(string $resourceClass, int $depth): array
    {
        $properties = [
            'id' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => [$this->jsonApiType($resourceClass)]],
        ];

        $attributes = $this->jsonApiAttributes($resourceClass, $depth);

        if ($attributes !== []) {
            $properties['attributes'] = ['type' => 'object', 'properties' => $attributes];
        }

        $relationships = $this->jsonApiRelationships($resourceClass);

        if ($relationships !== []) {
            $properties['relationships'] = ['type' => 'object', 'properties' => $relationships];
        }

        foreach (['toLinks' => 'links', 'toMeta' => 'meta'] as $method => $name) {
            $schema = $this->methodProperties($resourceClass, $method, $depth);

            if ($schema !== []) {
                $properties[$name] = ['type' => 'object', 'properties' => $schema];
            }
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function jsonApiAttributes(string $resourceClass, int $depth): array
    {
        $properties = $this->methodProperties($resourceClass, 'toAttributes', $depth);

        if ($properties !== []) {
            return $properties;
        }

        try {
            $attributes = (new ReflectionClass($resourceClass))->getDefaultProperties()['attributes'] ?? null;
        } catch (Throwable) {
            return [];
        }

        if (! is_array($attributes)) {
            return [];
        }

        $properties = [];

        foreach ($attributes as $key => $value) {
            $name = is_string($key) ? $key : (is_string($value) ? $value : null);

            if ($name !== null) {
                $properties[$name] = $this->fallback($name);
            }
        }

        return $properties;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function jsonApiRelationships(string $resourceClass): array
    {
        $array = $this->methodArray($resourceClass, 'toRelationships');
        $names = [];

        if ($array instanceof Node\Expr\Array_) {
            foreach ($array->items as $item) {
                if (! $item instanceof Node\ArrayItem) {
                    continue;
                }

                if ($item->key instanceof Scalar\String_) {
                    $names[] = $item->key->value;
                } elseif ($item->value instanceof Scalar\String_) {
                    $names[] = $item->value->value;
                }
            }
        }

        try {
            $relationships = (new ReflectionClass($resourceClass))->getDefaultProperties()['relationships'] ?? null;
        } catch (Throwable) {
            $relationships = null;
        }

        if (is_array($relationships)) {
            foreach ($relationships as $key => $value) {
                if (is_string($key)) {
                    $names[] = $key;
                } elseif (is_string($value) && ! class_exists($value)) {
                    $names[] = $value;
                }
            }
        }

        $schemas = [];

        foreach (array_values(array_unique($names)) as $name) {
            $schemas[$name] = [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'oneOf' => [
                            $this->jsonApiResourceIdentifier(),
                            ['type' => 'array', 'items' => $this->jsonApiResourceIdentifier()],
                            ['type' => 'null'],
                        ],
                    ],
                ],
            ];
        }

        return $schemas;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonApiResourceIdentifier(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
                'type' => ['type' => 'string'],
            ],
        ];
    }

    private function jsonApiType(string $resourceClass): string
    {
        $return = $this->methodReturnExpression($resourceClass, 'toType');

        if ($return instanceof Scalar\String_) {
            return $return->value;
        }

        return (string) Str::of(class_basename($resourceClass))
            ->beforeLast('Resource')
            ->snake()
            ->pluralStudly();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function methodProperties(string $class, string $method, int $depth): array
    {
        $array = $this->methodArray($class, $method);

        return $array instanceof Node\Expr\Array_ ? $this->properties($array, $depth) : [];
    }

    private function methodArray(string $class, string $method): ?Node\Expr\Array_
    {
        $expr = $this->methodReturnExpression($class, $method);

        return $expr instanceof Node\Expr\Array_ ? $expr : $this->arrayFromExpression($expr ?? new Node\Expr\Array_([]));
    }

    private function methodReturnExpression(string $class, string $method): ?Node\Expr
    {
        if (! method_exists($class, $method)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return null;
        }

        if ($reflection->getDeclaringClass()->getName() !== $class) {
            return null;
        }

        return $this->source->firstReturnExpression($reflection);
    }

    /**
     * Inline fields from mergeWhen([...]) / merge([...]) blocks.
     *
     * @return array<string, array<string, mixed>>
     */
    private function mergedProperties(Node\Expr $expr, int $depth): array
    {
        if (! $expr instanceof Node\Expr\MethodCall || ! $expr->name instanceof Node\Identifier) {
            return [];
        }

        $method = strtolower($expr->name->toString());

        if (! in_array($method, ['merge', 'mergewhen'], true)) {
            return [];
        }

        foreach ($expr->args as $arg) {
            if (! $arg instanceof Node\Arg) {
                continue;
            }

            $array = $this->arrayFromExpression($arg->value);

            if ($array instanceof Node\Expr\Array_) {
                $properties = $this->properties($array, $depth);

                if ($this->hasConditional($expr)) {
                    foreach ($properties as &$schema) {
                        $schema['nullable'] = true;
                    }
                }

                return $properties;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null null when the type can't be determined
     */
    private function inferType(Node\Expr $expr, int $depth): ?array
    {
        return match (true) {
            $expr instanceof Cast\Int_ => ['type' => 'integer'],
            $expr instanceof Cast\Double => ['type' => 'number'],
            $expr instanceof Cast\Bool_ => ['type' => 'boolean'],
            $expr instanceof Cast\String_ => ['type' => 'string'],
            $expr instanceof Cast\Array_ => ['type' => 'array'],
            $expr instanceof Scalar\Int_ => ['type' => 'integer'],
            $expr instanceof Scalar\Float_ => ['type' => 'number'],
            $expr instanceof Scalar\String_ => ['type' => 'string'],
            $expr instanceof Node\Expr\ConstFetch && in_array(strtolower($expr->name->toString()), ['true', 'false'], true) => ['type' => 'boolean'],
            $expr instanceof Node\Expr\Array_ => $this->arraySchema($expr, $depth),
            $expr instanceof Node\Expr\StaticCall => $this->resourceCallSchema($expr, $depth),
            $expr instanceof Node\Expr\New_ => $this->newSchema($expr, $depth),
            $expr instanceof Node\Expr\Ternary => $this->inferType($expr->else, $depth),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function arraySchema(Node\Expr\Array_ $array, int $depth): array
    {
        $hasStringKey = false;

        foreach ($array->items as $item) {
            if ($item instanceof Node\ArrayItem && $item->key instanceof Scalar\String_) {
                $hasStringKey = true;
                break;
            }
        }

        if ($hasStringKey) {
            $properties = $this->properties($array, $depth);

            return $properties === [] ? self::OBJECT : ['type' => 'object', 'properties' => $properties];
        }

        return ['type' => 'array', 'items' => $this->listItemSchema($array, $depth)];
    }

    /**
     * @return array<string, mixed>
     */
    private function listItemSchema(Node\Expr\Array_ $array, int $depth): array
    {
        foreach ($array->items as $item) {
            if (! $item instanceof Node\ArrayItem) {
                continue;
            }

            return $this->inferType($item->value, $depth) ?? self::OBJECT;
        }

        return self::OBJECT;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resourceCallSchema(Node\Expr\StaticCall $call, int $depth): ?array
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return null;
        }

        $class = $call->class->toString();

        if (! is_subclass_of($class, JsonResource::class)) {
            return null;
        }

        if ($call->name->toString() === 'collection') {
            return ['type' => 'array', 'items' => $this->extract($class, $depth + 1)];
        }

        return $this->extract($class, $depth + 1);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function newSchema(Node\Expr\New_ $new, int $depth): ?array
    {
        if (! $new->class instanceof Node\Name) {
            return null;
        }

        $class = $new->class->toString();

        return is_subclass_of($class, JsonResource::class) ? $this->extract($class, $depth + 1) : null;
    }

    private function isConditional(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && in_array(strtolower($expr->name->toString()), self::CONDITIONALS, true);
    }

    private function hasConditional(Node\Expr $expr): bool
    {
        return (new NodeFinder)->findFirst(
            $expr,
            fn (Node $node) => $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && in_array(strtolower($node->name->toString()), self::CONDITIONALS, true),
        ) !== null;
    }

    /**
     * Look for a nested resource inside a when()/whenLoaded() argument.
     *
     * @return array<string, mixed>|null
     */
    private function conditionalNested(Node\Expr\MethodCall $call, int $depth): ?array
    {
        foreach ($call->args as $arg) {
            if (! $arg instanceof Node\Arg) {
                continue;
            }
            if ($arg->value instanceof Node\Expr\StaticCall && ($schema = $this->resourceCallSchema($arg->value, $depth))) {
                return $schema;
            }
            if ($arg->value instanceof Node\Expr\New_ && ($schema = $this->newSchema($arg->value, $depth))) {
                return $schema;
            }
            if (($array = $this->arrayFromExpression($arg->value)) instanceof Node\Expr\Array_) {
                return $this->arraySchema($array, $depth);
            }
        }

        return null;
    }

    private function arrayFromExpression(Node\Expr $expr): ?Node\Expr\Array_
    {
        if ($expr instanceof Node\Expr\Array_) {
            return $expr;
        }

        if ($expr instanceof Node\Expr\Closure) {
            foreach ($expr->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node\Expr\Array_) {
                    return $stmt->expr;
                }
            }
        }

        if ($expr instanceof Node\Expr\ArrowFunction && $expr->expr instanceof Node\Expr\Array_) {
            return $expr->expr;
        }

        return null;
    }

    /**
     * Type for a property whose value expression we couldn't read: prefer the
     * wrapped model's cast, then a field-name heuristic.
     *
     * @return array<string, mixed>
     */
    private function fallback(string $name): array
    {
        return $this->castSchema($name) ?? $this->currentDocProps[$name] ?? $this->hintFromName($name);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function castSchema(string $name): ?array
    {
        $cast = $this->currentCasts[$name] ?? null;

        if ($cast === null) {
            return null;
        }

        $base = strtolower(explode(':', $cast)[0]);

        if (enum_exists($cast)) {
            return ['type' => 'string', 'enum' => array_map(
                fn ($case) => $case->value ?? $case->name,
                $cast::cases(),
            )];
        }

        return match ($base) {
            'int', 'integer' => ['type' => 'integer'],
            'real', 'float', 'double', 'decimal' => ['type' => 'number'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'array', 'json', 'collection', 'encrypted:array', 'encrypted:json' => ['type' => 'array'],
            'object' => ['type' => 'object'],
            'date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp' => ['type' => 'string', 'format' => 'date-time'],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function hintFromName(string $name): array
    {
        $name = strtolower($name);

        return match (true) {
            $name === 'id', str_ends_with($name, '_id') => ['type' => 'integer'],
            str_ends_with($name, '_at') => ['type' => 'string', 'format' => 'date-time'],
            str_starts_with($name, 'is_'), str_starts_with($name, 'has_') => ['type' => 'boolean'],
            str_ends_with($name, '_count'), in_array($name, ['count', 'quantity', 'qty', 'total'], true) => ['type' => 'integer'],
            default => ['type' => 'string'],
        };
    }

    /**
     * @return array<string, string>
     */
    private function modelCasts(string $model): array
    {
        try {
            return (new $model)->getCasts();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Property types declared in the model's `@property` docblock (covers
     * columns that aren't cast). Common when using laravel-ide-helper.
     *
     * @return array<string, array<string, mixed>>
     */
    private function modelDocProps(string $model): array
    {
        try {
            $doc = (new ReflectionClass($model))->getDocComment();
        } catch (Throwable) {
            return [];
        }

        if ($doc === false) {
            return [];
        }

        preg_match_all('/@property(?:-read|-write)?\s+(\S+)\s+\$(\w+)/', $doc, $matches, PREG_SET_ORDER);

        $props = [];
        foreach ($matches as $match) {
            $props[$match[2]] = $this->docType($match[1]);
        }

        return $props;
    }

    /**
     * @return array<string, mixed>
     */
    private function docType(string $raw): array
    {
        $nullable = str_contains($raw, '?');
        $parts = array_map(fn (string $p) => strtolower(ltrim($p, '\\')), explode('|', str_replace('?', '', $raw)));

        if (in_array('null', $parts, true)) {
            $nullable = true;
        }

        $parts = array_values(array_filter($parts, fn (string $p) => $p !== 'null' && $p !== ''));
        $first = $parts[0] ?? 'string';

        $schema = match (true) {
            in_array($first, ['int', 'integer'], true) => ['type' => 'integer'],
            in_array($first, ['float', 'double'], true) => ['type' => 'number'],
            in_array($first, ['bool', 'boolean', 'true', 'false'], true) => ['type' => 'boolean'],
            in_array($first, ['array', 'iterable'], true) => ['type' => 'array'],
            str_contains($first, 'carbon'), in_array($first, ['datetime', 'datetimeinterface', 'datetimeimmutable'], true) => ['type' => 'string', 'format' => 'date-time'],
            default => ['type' => 'string'],
        };

        if ($nullable) {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    private function resolveModel(string $resourceClass): ?string
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
        } catch (Throwable) {
            return null;
        }

        foreach ($reflection->getAttributes(UsesModel::class) as $attribute) {
            return $attribute->newInstance()->model;
        }

        $base = preg_replace('/(Resource|Collection)$/', '', class_basename($resourceClass));
        $namespace = (string) (function_exists('config') ? config('documentator.models_namespace', 'App\\Models') : 'App\\Models');
        $candidate = $namespace.'\\'.$base;

        return class_exists($candidate) && is_subclass_of($candidate, Model::class) ? $candidate : null;
    }
}
