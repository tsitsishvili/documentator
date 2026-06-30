<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Support;

use BackedEnum;
use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use Tsitsishvili\Documentator\Data\ParameterData;
use Tsitsishvili\Documentator\OpenApi\SchemaType;

/**
 * Finds literal inline Laravel validation arrays in controller methods and
 * closure route actions.
 * Dynamic rule variables are skipped so documentation generation stays
 * conservative and non-failing.
 */
final class InlineValidationRulesExtractor
{
    public function __construct(private readonly SourceAnalyzer $source) {}

    /**
     * @return array<string, mixed>
     */
    public function rulesFor(ReflectionFunctionAbstract $action): array
    {
        $functionNode = $this->source->functionLikeNode($action);

        if ($functionNode === null) {
            return [];
        }

        $requestNames = $this->requestParameterNames($action);
        $rules = [];

        foreach ((new NodeFinder)->find($functionNode, fn (Node $node) => $node instanceof Node\Expr\MethodCall) as $call) {
            if (! $call instanceof Node\Expr\MethodCall) {
                continue;
            }

            $array = $this->validationRulesArray($call, $requestNames);

            if ($array instanceof Node\Expr\Array_) {
                $rules = array_replace($rules, $this->rulesFromArray($array));
            }
        }

        foreach ((new NodeFinder)->find($functionNode, fn (Node $node) => $node instanceof Node\Expr\StaticCall) as $call) {
            if (! $call instanceof Node\Expr\StaticCall || ! $this->isValidatorMakeCall($call)) {
                continue;
            }

            $array = $this->argumentArray($call->args, 1);

            if ($array instanceof Node\Expr\Array_) {
                $rules = array_replace($rules, $this->rulesFromArray($array));
            }
        }

        return $rules;
    }

    /**
     * @return array<int, array{parameter: ParameterData, location: 'query'|'body'|null}>
     */
    public function requestAccessorsFor(ReflectionFunctionAbstract $action): array
    {
        $functionNode = $this->source->functionLikeNode($action);

        if ($functionNode === null) {
            return [];
        }

        $requestNames = $this->requestParameterNames($action);
        $parameters = [];

        foreach ((new NodeFinder)->find($functionNode, fn (Node $node) => $node instanceof Node\Expr\MethodCall) as $call) {
            if (! $call instanceof Node\Expr\MethodCall || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            if (! $this->isRequestExpression($call->var, $requestNames)) {
                continue;
            }

            $method = strtolower($call->name->toString());

            if (! in_array($method, $this->requestAccessorMethods(), true)) {
                continue;
            }

            $name = $this->stringArgument($call->args, 0);

            if ($name === null || $name === '') {
                continue;
            }

            $parameters[$method.'|'.$name] = [
                'parameter' => new ParameterData(
                    name: $name,
                    type: SchemaType::fromRequestAccessor($method, $name)->toSchema()['type'],
                    schema: SchemaType::fromRequestAccessor($method, $name)->toSchema(),
                ),
                'location' => $method === 'query' ? 'query' : ($method === 'post' ? 'body' : null),
            ];
        }

        foreach ((new NodeFinder)->find($functionNode, fn (Node $node) => $node instanceof Node\Expr\FuncCall) as $call) {
            if (! $call instanceof Node\Expr\FuncCall || ! $this->isNamedCall($call, 'request')) {
                continue;
            }

            $name = $this->stringArgument($call->args, 0);

            if ($name === null || $name === '') {
                continue;
            }

            $schema = SchemaType::fromName($name)->toSchema();
            $parameters['request|'.$name] = [
                'parameter' => new ParameterData(
                    name: $name,
                    type: $schema['type'],
                    schema: $schema,
                ),
                'location' => null,
            ];
        }

        return array_values($parameters);
    }

    /**
     * @return array<int, string>
     */
    private function requestParameterNames(ReflectionFunctionAbstract $action): array
    {
        $names = [];

        foreach ($action->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $class = $type->getName();

                if (is_a($class, Request::class, true)) {
                    $names[] = $parameter->getName();
                }
            }
        }

        if ($names === []) {
            $names[] = 'request';
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $requestNames
     */
    private function validationRulesArray(Node\Expr\MethodCall $call, array $requestNames): ?Node\Expr\Array_
    {
        if (! $call->name instanceof Node\Identifier) {
            return null;
        }

        $method = strtolower($call->name->toString());

        if (in_array($method, ['validate', 'validatewithbag'], true) && $this->isRequestExpression($call->var, $requestNames)) {
            return $this->argumentArray($call->args, $method === 'validatewithbag' ? 1 : 0);
        }

        if ($method === 'validate' && $call->var instanceof Node\Expr\StaticCall && $this->isValidatorMakeCall($call->var)) {
            return $this->argumentArray($call->var->args, 1);
        }

        if ($method === 'validate' && $call->var instanceof Node\Expr\FuncCall && $this->isNamedCall($call->var, 'validator')) {
            return $this->argumentArray($call->var->args, 1);
        }

        if (
            $method === 'validate'
            && $call->var instanceof Node\Expr\Variable
            && $call->var->name === 'this'
            && ($call->args[0]->value ?? null) instanceof Node\Expr
            && $this->isRequestExpression($call->args[0]->value, $requestNames)
        ) {
            return $this->argumentArray($call->args, 1);
        }

        return null;
    }

    /**
     * @param  array<int, Node\Arg>  $args
     */
    private function argumentArray(array $args, int $index): ?Node\Expr\Array_
    {
        return ($args[$index]->value ?? null) instanceof Node\Expr\Array_
            ? $args[$index]->value
            : null;
    }

    /**
     * @param  array<int, Node\Arg>  $args
     */
    private function stringArgument(array $args, int $index): ?string
    {
        $value = $args[$index]->value ?? null;

        return $value instanceof Node\Scalar\String_ ? $value->value : null;
    }

    /**
     * @param  array<int, string>  $requestNames
     */
    private function isRequestExpression(Node\Expr $expr, array $requestNames): bool
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return in_array($expr->name, $requestNames, true);
        }

        return $expr instanceof Node\Expr\FuncCall && $this->isNamedCall($expr, 'request');
    }

    private function isValidatorMakeCall(Node\Expr\StaticCall $call): bool
    {
        if (! $call->name instanceof Node\Identifier || strtolower($call->name->toString()) !== 'make') {
            return false;
        }

        return $call->class instanceof Node\Name
            && in_array(ltrim($call->class->toString(), '\\'), ['Illuminate\Support\Facades\Validator', 'Validator'], true);
    }

    private function isNamedCall(Node\Expr\FuncCall $call, string $name): bool
    {
        return $call->name instanceof Node\Name && strtolower($call->name->toString()) === $name;
    }

    /**
     * @return array<int, string>
     */
    private function requestAccessorMethods(): array
    {
        return [
            'input',
            'query',
            'post',
            'get',
            'string',
            'integer',
            'int',
            'float',
            'double',
            'boolean',
            'bool',
            'array',
            'collect',
            'date',
            'file',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesFromArray(Node\Expr\Array_ $array): array
    {
        $rules = [];

        foreach ($array->items as $item) {
            if (! $item instanceof Node\ArrayItem || ! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $ruleSet = $this->ruleSet($item->value);

            if ($ruleSet !== null) {
                $rules[$item->key->value] = $ruleSet;
            }
        }

        return $rules;
    }

    private function ruleSet(Node\Expr $expr): mixed
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if (! $expr instanceof Node\Expr\Array_) {
            return null;
        }

        $rules = [];

        foreach ($expr->items as $item) {
            if (! $item instanceof Node\ArrayItem) {
                continue;
            }

            $rule = $this->ruleString($item->value);

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules === [] ? null : $rules;
    }

    private function ruleString(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if (! $expr instanceof Node\Expr\StaticCall || ! $expr->name instanceof Node\Identifier) {
            return null;
        }

        $method = strtolower($expr->name->toString());

        if (! $this->isValidationRuleClass($expr->class)) {
            return null;
        }

        if ($method === 'in') {
            $values = $this->valuesFromRuleArgument($expr->args[0]->value ?? null);

            return $values === [] ? null : 'in:'.implode(',', $values);
        }

        if ($method === 'enum') {
            $values = $this->enumValuesFromRuleArgument($expr->args[0]->value ?? null);

            return $values === [] ? null : 'in:'.implode(',', $values);
        }

        return null;
    }

    private function isValidationRuleClass(Node\Name|Node\Expr $class): bool
    {
        return $class instanceof Node\Name
            && in_array(ltrim($class->toString(), '\\'), ['Illuminate\Validation\Rule', 'Rule'], true);
    }

    /**
     * @return array<int, string>
     */
    private function valuesFromRuleArgument(?Node\Expr $expr): array
    {
        if ($expr instanceof Node\Expr\Array_) {
            return $this->literalValues($expr);
        }

        return ($value = $this->literalValue($expr)) === null ? [] : [$value];
    }

    /**
     * @return array<int, string>
     */
    private function enumValuesFromRuleArgument(?Node\Expr $expr): array
    {
        if (! $expr instanceof Node\Expr\ClassConstFetch || strtolower($expr->name->toString()) !== 'class') {
            return [];
        }

        if (! $expr->class instanceof Node\Name) {
            return [];
        }

        $class = ltrim($expr->class->toString(), '\\');

        if (! enum_exists($class)) {
            return [];
        }

        return array_map(
            static fn ($case): string => $case instanceof BackedEnum ? (string) $case->value : $case->name,
            $class::cases(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function literalValues(Node\Expr\Array_ $array): array
    {
        $values = [];

        foreach ($array->items as $item) {
            if (! $item instanceof Node\ArrayItem) {
                continue;
            }

            $value = $this->literalValue($item->value);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function literalValue(?Node\Expr $expr): ?string
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_ => $expr->value,
            $expr instanceof Node\Scalar\Int_ => (string) $expr->value,
            $expr instanceof Node\Scalar\Float_ => (string) $expr->value,
            $expr instanceof Node\Expr\ConstFetch && in_array(strtolower($expr->name->toString()), ['true', 'false'], true) => strtolower($expr->name->toString()),
            default => null,
        };
    }
}
