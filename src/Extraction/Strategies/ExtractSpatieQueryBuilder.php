<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Routing\Route;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Throwable;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ParameterData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\Extraction\Support\RouteActionReflection;
use Tsitsishvili\Documentator\Extraction\Support\SourceAnalyzer;
use Tsitsishvili\Documentator\OpenApi\SchemaType;

/**
 * Best-effort support for spatie/laravel-query-builder. The package is optional;
 * this strategy only reads literal allowed* calls from source and never invokes
 * QueryBuilder itself.
 */
final class ExtractSpatieQueryBuilder implements ExtractionStrategy
{
    public function __construct(private readonly SourceAnalyzer $source) {}

    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        $action = RouteActionReflection::for($route, $method);
        $functionNode = $action === null ? null : $this->source->functionLikeNode($action);

        if ($functionNode === null) {
            return $endpoint;
        }

        $functionNodes = $this->functionNodes($functionNode, $action);
        $queryBuilderMethods = $this->queryBuilderReturningMethods($functionNodes);
        $queryBuilderVariables = $this->queryBuilderVariables($functionNodes, $queryBuilderMethods);
        $literalVariables = $this->literalVariables($functionNodes, $action);

        foreach ($functionNodes as $node) {
            foreach ((new NodeFinder)->find($node, fn (Node $node) => $node instanceof Node\Expr\MethodCall) as $call) {
                if (! $call instanceof Node\Expr\MethodCall || ! $call->name instanceof Node\Identifier) {
                    continue;
                }

                if (! $this->isQueryBuilderCall($call, $queryBuilderVariables, $queryBuilderMethods)) {
                    continue;
                }

                $methodName = strtolower($call->name->toString());
                $items = $this->allowedItems($call, $literalVariables, $action);

                if ($items === []) {
                    continue;
                }

                match ($methodName) {
                    'allowedfilters' => $this->addFilters($endpoint, $items),
                    'allowedsorts' => $this->addSorts($endpoint, $items),
                    'allowedincludes' => $this->addDelimitedEnum($endpoint, 'include', $this->names($items), 'Allowed relationship includes. Multiple values may be comma-separated.'),
                    'allowedfields' => $this->addFields($endpoint, $this->names($items)),
                    'defaultsort' => $this->addDefaultSort($endpoint, $this->names($items)),
                    default => null,
                };
            }
        }

        return $endpoint;
    }

    /**
     * @return array<int, Node>
     */
    private function functionNodes(Node $functionNode, ReflectionFunctionAbstract $action): array
    {
        $nodes = [$functionNode];

        if (! $action instanceof ReflectionMethod) {
            return $nodes;
        }

        foreach ($this->calledThisMethods($functionNode) as $name) {
            if (! method_exists($action->class, $name)) {
                continue;
            }

            try {
                $method = new ReflectionMethod($action->class, $name);
            } catch (Throwable) {
                continue;
            }

            $node = $this->source->methodNode($method);

            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @return array<int, string>
     */
    private function calledThisMethods(Node $functionNode): array
    {
        $methods = [];

        foreach ((new NodeFinder)->find($functionNode, fn (Node $node) => $node instanceof Node\Expr\MethodCall) as $call) {
            if (! $call instanceof Node\Expr\MethodCall
                || ! $call->var instanceof Node\Expr\Variable
                || $call->var->name !== 'this'
                || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            $methods[] = $call->name->toString();
        }

        return array_values(array_unique($methods));
    }

    /**
     * @param  array<int, Node>  $functionNodes
     * @return array<int, string>
     */
    private function queryBuilderReturningMethods(array $functionNodes): array
    {
        $methods = [];

        foreach ($functionNodes as $node) {
            if (! $node instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            $return = (new NodeFinder)->findFirst(
                $node,
                fn (Node $node) => $node instanceof Node\Stmt\Return_
                    && $node->expr instanceof Node\Expr
                    && $this->containsQueryBuilderFor($node->expr, []),
            );

            if ($return instanceof Node\Stmt\Return_) {
                $methods[] = $node->name->toString();
            }
        }

        return array_values(array_unique($methods));
    }

    /**
     * @param  array<int, Node>  $functionNodes
     * @return array<string, array<int, array{name: string, ignored: array<int, string>}>>
     */
    private function literalVariables(array $functionNodes, ReflectionFunctionAbstract $action): array
    {
        $variables = [];

        foreach ($functionNodes as $functionNode) {
            foreach ((new NodeFinder)->find($functionNode, fn (Node $node) => $node instanceof Node\Expr\Assign) as $assign) {
                if (! $assign instanceof Node\Expr\Assign
                    || ! $assign->var instanceof Node\Expr\Variable
                    || ! is_string($assign->var->name)) {
                    continue;
                }

                $items = $this->itemsFromExpression($assign->expr, $variables, $action);

                if ($items !== []) {
                    $variables[$assign->var->name] = $items;
                }
            }
        }

        return $variables;
    }

    /**
     * @param  array<int, Node>  $functionNodes
     * @param  array<int, string>  $queryBuilderMethods
     * @return array<int, string>
     */
    private function queryBuilderVariables(array $functionNodes, array $queryBuilderMethods): array
    {
        $variables = [];

        foreach ($functionNodes as $functionNode) {
            foreach ((new NodeFinder)->find($functionNode, fn (Node $node) => $node instanceof Node\Expr\Assign) as $assign) {
                if (! $assign instanceof Node\Expr\Assign
                    || ! $assign->var instanceof Node\Expr\Variable
                    || ! is_string($assign->var->name)) {
                    continue;
                }

                if ($this->containsQueryBuilderFor($assign->expr, $queryBuilderMethods)) {
                    $variables[] = $assign->var->name;
                }
            }
        }

        return array_values(array_unique($variables));
    }

    /**
     * @param  array<int, string>  $queryBuilderVariables
     */
    private function isQueryBuilderCall(Node\Expr\MethodCall $call, array $queryBuilderVariables, array $queryBuilderMethods): bool
    {
        if ($this->containsQueryBuilderFor($call->var, $queryBuilderMethods)) {
            return true;
        }

        return $this->containsQueryBuilderVariable($call->var, $queryBuilderVariables);
    }

    /**
     * @param  array<int, string>  $queryBuilderMethods
     */
    private function containsQueryBuilderFor(Node\Expr $expr, array $queryBuilderMethods): bool
    {
        if ($expr instanceof Node\Expr\StaticCall
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'for') {
            $class = ltrim($expr->class->toString(), '\\');

            return in_array($class, ['Spatie\QueryBuilder\QueryBuilder', 'QueryBuilder'], true)
                || str_ends_with(class_basename($class), 'QueryBuilder');
        }

        if ($expr instanceof Node\Expr\MethodCall) {
            if ($expr->var instanceof Node\Expr\Variable
                && $expr->var->name === 'this'
                && $expr->name instanceof Node\Identifier
                && in_array($expr->name->toString(), $queryBuilderMethods, true)) {
                return true;
            }

            return $this->containsQueryBuilderFor($expr->var, $queryBuilderMethods);
        }

        return false;
    }

    /**
     * @param  array<int, string>  $queryBuilderVariables
     */
    private function containsQueryBuilderVariable(Node\Expr $expr, array $queryBuilderVariables): bool
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return in_array($expr->name, $queryBuilderVariables, true);
        }

        return $expr instanceof Node\Expr\MethodCall
            && $this->containsQueryBuilderVariable($expr->var, $queryBuilderVariables);
    }

    /**
     * @param  array<string, array<int, array{name: string, ignored: array<int, string>}>>  $variables
     * @return array<int, array{name: string, ignored: array<int, string>}>
     */
    private function allowedItems(Node\Expr\MethodCall $call, array $variables, ReflectionFunctionAbstract $action): array
    {
        if (! $call->name instanceof Node\Identifier
            || ! in_array(strtolower($call->name->toString()), ['allowedfilters', 'allowedsorts', 'allowedincludes', 'allowedfields', 'defaultsort'], true)) {
            return [];
        }

        $items = [];

        foreach ($call->args as $arg) {
            $items = array_merge($items, $this->itemsFromExpression($arg->value, $variables, $action));
        }

        return $this->uniqueItems($items);
    }

    /**
     * @param  array<string, array<int, array{name: string, ignored: array<int, string>}>>  $variables
     * @return array<int, array{name: string, ignored: array<int, string>}>
     */
    private function itemsFromExpression(?Node\Expr $expr, array $variables, ReflectionFunctionAbstract $action): array
    {
        if ($expr instanceof Node\Expr\Array_) {
            $items = [];

            foreach ($expr->items as $item) {
                if ($item instanceof Node\ArrayItem) {
                    $items = array_merge($items, $this->itemsFromExpression($item->value, $variables, $action));
                }
            }

            return $items;
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return $variables[$expr->name] ?? [];
        }

        if ($expr instanceof Node\Expr\ClassConstFetch) {
            return $this->itemsFromConstant($expr, $action);
        }

        $item = $this->itemFromExpression($expr);

        return $item === null ? [] : [$item];
    }

    /**
     * @return array<int, array{name: string, ignored: array<int, string>}>
     */
    private function itemsFromConstant(Node\Expr\ClassConstFetch $fetch, ReflectionFunctionAbstract $action): array
    {
        if (! $fetch->name instanceof Node\Identifier) {
            return [];
        }

        $class = $fetch->class instanceof Node\Name ? ltrim($fetch->class->toString(), '\\') : null;

        if (in_array($class, ['self', 'static'], true) && $action instanceof ReflectionMethod) {
            $class = $action->getDeclaringClass()->getName();
        }

        if ($class === null || ! class_exists($class)) {
            return [];
        }

        try {
            $value = (new ReflectionClass($class))->getConstant($fetch->name->toString());
        } catch (Throwable) {
            return [];
        }

        if (is_string($value)) {
            return [['name' => ltrim($value, '-'), 'ignored' => []]];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = ['name' => ltrim($item, '-'), 'ignored' => []];
            }
        }

        return $items;
    }

    /**
     * @return array{name: string, ignored: array<int, string>}|null
     */
    private function itemFromExpression(?Node\Expr $expr): ?array
    {
        if ($expr instanceof Node\Scalar\String_) {
            return ['name' => ltrim($expr->value, '-'), 'ignored' => []];
        }

        if ($expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'ignore') {
            $item = $this->itemFromExpression($expr->var);

            if ($item !== null) {
                $item['ignored'] = array_merge($item['ignored'], $this->literalArguments($expr->args));
            }

            return $item;
        }

        if ($expr instanceof Node\Expr\StaticCall) {
            return $this->stringArgument($expr->args, 0);
        }

        if ($expr instanceof Node\Expr\New_) {
            return $this->stringArgument($expr->args, 0);
        }

        return null;
    }

    /**
     * @param  array<int, Node\Arg>  $args
     */
    private function stringArgument(array $args, int $index): ?array
    {
        $value = $args[$index]->value ?? null;

        return $value instanceof Node\Scalar\String_
            ? ['name' => ltrim($value->value, '-'), 'ignored' => []]
            : null;
    }

    /**
     * @param  array<int, Node\Arg>  $args
     * @return array<int, string>
     */
    private function literalArguments(array $args): array
    {
        $values = [];

        foreach ($args as $arg) {
            $expr = $arg->value;

            if ($expr instanceof Node\Scalar\String_
                || $expr instanceof Node\Scalar\Int_
                || $expr instanceof Node\Scalar\Float_) {
                $values[] = (string) $expr->value;
            } elseif ($expr instanceof Node\Expr\ConstFetch) {
                $values[] = strtolower($expr->name->toString());
            }
        }

        return $values;
    }

    /**
     * @param  array<int, array{name: string, ignored: array<int, string>}>  $items
     * @return array<int, array{name: string, ignored: array<int, string>}>
     */
    private function uniqueItems(array $items): array
    {
        $unique = [];

        foreach ($items as $item) {
            $unique[$item['name']] ??= ['name' => $item['name'], 'ignored' => []];
            $unique[$item['name']]['ignored'] = array_values(array_unique(array_merge($unique[$item['name']]['ignored'], $item['ignored'])));
        }

        return array_values($unique);
    }

    /**
     * @param  array<int, array{name: string, ignored: array<int, string>}>  $items
     * @return array<int, string>
     */
    private function names(array $items): array
    {
        return array_values(array_unique(array_map(fn (array $item): string => $item['name'], $items)));
    }

    /**
     * @param  array<int, array{name: string, ignored: array<int, string>}>  $items
     */
    private function addFilters(EndpointData $endpoint, array $items): void
    {
        foreach ($items as $item) {
            $name = $item['name'];
            $parameter = 'filter['.$name.']';
            $schema = SchemaType::fromName($name)->toSchema();
            $ignored = $item['ignored'] === [] ? '' : ' Ignored values: `'.implode('`, `', $item['ignored']).'`.';

            $endpoint->queryParameters[$parameter] ??= new ParameterData(
                name: $parameter,
                type: $schema['type'],
                required: false,
                description: 'Allowed filter.'.$ignored,
                schema: $schema,
            );
        }
    }

    /**
     * @param  array<int, array{name: string, ignored: array<int, string>}>  $items
     */
    private function addSorts(EndpointData $endpoint, array $items): void
    {
        $this->addDelimitedEnum($endpoint, 'sort', $this->names($items), 'Allowed sort fields. Prefix with `-` for descending order.', true);
    }

    /**
     * @param  array<int, string>  $names
     */
    private function addDefaultSort(EndpointData $endpoint, array $names): void
    {
        $normalized = array_map(fn (string $name): string => ltrim($name, '-'), $names);
        $this->addDelimitedEnum($endpoint, 'sort', $normalized, 'Allowed sort fields. Prefix with `-` for descending order.', true);

        if (isset($endpoint->queryParameters['sort']) && $names !== []) {
            $description = $endpoint->queryParameters['sort']->description ?? 'Allowed sort fields. Prefix with `-` for descending order.';
            $endpoint->queryParameters['sort']->description = $description.' Default: `'.implode('`, `', $names).'`.';
        }
    }

    /**
     * @param  array<int, string>  $names
     */
    private function addDelimitedEnum(EndpointData $endpoint, string $name, array $names, string $description, bool $descending = false): void
    {
        $enum = $names;

        if ($descending) {
            $enum = array_values(array_unique(array_merge($enum, array_map(fn (string $value): string => '-'.$value, $names))));
        }

        if (isset($endpoint->queryParameters[$name])) {
            $schema = $endpoint->queryParameters[$name]->schema ?? ['type' => 'string'];
            $schema['enum'] = array_values(array_unique(array_merge($schema['enum'] ?? [], $enum)));
            $endpoint->queryParameters[$name]->schema = $schema;

            return;
        }

        $endpoint->queryParameters[$name] = new ParameterData(
            name: $name,
            type: 'string',
            required: false,
            description: $description,
            schema: ['type' => 'string', 'enum' => $enum],
        );
    }

    /**
     * @param  array<int, string>  $names
     */
    private function addFields(EndpointData $endpoint, array $names): void
    {
        $grouped = [];

        foreach ($names as $name) {
            if (str_contains($name, '.')) {
                [$resource, $field] = explode('.', $name, 2);
                $grouped[$resource][] = $field;
            } else {
                $grouped[''][] = $name;
            }
        }

        foreach ($grouped as $resource => $fields) {
            $parameter = $resource === '' ? 'fields' : 'fields['.$resource.']';
            $fields = array_values(array_unique($fields));

            $endpoint->queryParameters[$parameter] ??= new ParameterData(
                name: $parameter,
                type: 'string',
                required: false,
                description: 'Allowed sparse fieldset fields. Multiple values may be comma-separated.',
                schema: ['type' => 'string', 'enum' => $fields],
            );
        }
    }
}
