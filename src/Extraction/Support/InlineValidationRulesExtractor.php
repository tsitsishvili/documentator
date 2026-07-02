<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Support;

use BackedEnum;
use Illuminate\Http\Request;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use Tsitsishvili\Documentator\Data\ParameterData;
use Tsitsishvili\Documentator\OpenApi\SchemaType;
use Tsitsishvili\Documentator\OpenApi\TypeStringParser;

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
        $docs = $this->requestAccessorDocs($functionNode, $requestNames);

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
                'parameter' => $this->withDocs(new ParameterData(
                    name: $name,
                    type: SchemaType::fromRequestAccessor($method, $name)->toSchema()['type'],
                    schema: SchemaType::fromRequestAccessor($method, $name)->toSchema(),
                ), $docs[$name] ?? []),
                'location' => $docs[$name]['location'] ?? ($method === 'query' ? 'query' : ($method === 'post' ? 'body' : null)),
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
                'parameter' => $this->withDocs(new ParameterData(
                    name: $name,
                    type: $schema['type'],
                    schema: $schema,
                ), $docs[$name] ?? []),
                'location' => $docs[$name]['location'] ?? null,
            ];
        }

        return array_values(array_filter($parameters, fn (array $item): bool => $item['parameter'] instanceof ParameterData));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function parameterDocsFor(ReflectionFunctionAbstract $action): array
    {
        $functionNode = $this->source->functionLikeNode($action);

        if ($functionNode === null) {
            return [];
        }

        $requestNames = $this->requestParameterNames($action);
        $docs = $this->requestAccessorDocs($functionNode, $requestNames);

        foreach ((new NodeFinder)->find($functionNode, fn (Node $node) => $node instanceof Node\Expr\Array_) as $array) {
            if (! $array instanceof Node\Expr\Array_) {
                continue;
            }

            foreach ($array->items as $item) {
                if (! $item instanceof Node\ArrayItem || ! $item->key instanceof Node\Scalar\String_) {
                    continue;
                }

                $parsed = $this->parseDocComments($item->getComments());

                if ($parsed !== []) {
                    $name = $this->docKeyForField($item->key->value);
                    $docs[$name] = array_replace($docs[$name] ?? [], $parsed);
                }
            }
        }

        return $docs;
    }

    /**
     * @param  array<string, mixed>  $docs
     */
    public function withDocs(ParameterData $parameter, array $docs): ?ParameterData
    {
        if (($docs['ignore'] ?? false) === true) {
            return null;
        }

        if (isset($docs['type']) && is_string($docs['type'])) {
            $schema = TypeStringParser::parse($docs['type']);

            if ($schema !== null) {
                $parameter->type = is_string($schema['type'] ?? null) ? $schema['type'] : $parameter->type;
                $parameter->schema = array_replace($parameter->schema ?? [], $schema);
            }
        }

        if (isset($docs['description']) && is_string($docs['description']) && $docs['description'] !== '') {
            $parameter->description = $docs['description'];
        }

        if (array_key_exists('example', $docs)) {
            $parameter->example = $docs['example'];
        }

        if (array_key_exists('default', $docs)) {
            $schema = $parameter->schema ?? ['type' => $parameter->type];
            $schema['default'] = $docs['default'];
            $parameter->schema = $schema;
        }

        return $parameter;
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

            array_push($rules, ...$this->ruleStrings($item->value));
        }

        return $rules === [] ? null : $rules;
    }

    /**
     * @return array<int, string>
     */
    private function ruleStrings(Node\Expr $expr): array
    {
        if ($expr instanceof Node\Scalar\String_) {
            return [$expr->value];
        }

        if (! $expr instanceof Node\Expr\StaticCall || ! $expr->name instanceof Node\Identifier) {
            return [];
        }

        $method = strtolower($expr->name->toString());

        if (! $this->isValidationRuleClass($expr->class)) {
            return [];
        }

        if ($method === 'in') {
            $values = $this->valuesFromRuleArgument($expr->args[0]->value ?? null);

            return $values === [] ? [] : ['in:'.implode(',', $values)];
        }

        if ($method === 'enum') {
            $values = $this->enumValuesFromRuleArgument($expr->args[0]->value ?? null);

            return $values === [] ? [] : ['in:'.implode(',', $values)];
        }

        if ($method === 'exists') {
            $table = $this->literalValue($expr->args[0]->value ?? null);
            $column = $this->literalValue($expr->args[1]->value ?? null) ?? 'NULL';

            return $table === null ? [] : ['exists:'.$table.','.$column];
        }

        if (in_array($method, ['when', 'unless'], true)) {
            return array_merge(
                $this->rulesFromRuleExpression($expr->args[1]->value ?? null),
                $this->rulesFromRuleExpression($expr->args[2]->value ?? null),
            );
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function rulesFromRuleExpression(?Node\Expr $expr): array
    {
        if ($expr instanceof Node\Expr\Array_) {
            $rules = [];

            foreach ($expr->items as $item) {
                if ($item instanceof Node\ArrayItem) {
                    array_push($rules, ...$this->ruleStrings($item->value));
                }
            }

            return $rules;
        }

        return $expr instanceof Node\Expr ? $this->ruleStrings($expr) : [];
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

    /**
     * @param  array<int, string>  $requestNames
     * @return array<string, array<string, mixed>>
     */
    private function requestAccessorDocs(Node $functionNode, array $requestNames): array
    {
        $docs = [];

        foreach ((new NodeFinder)->find($functionNode, fn (Node $node) => $node instanceof Node\Stmt\Expression) as $statement) {
            if (! $statement instanceof Node\Stmt\Expression) {
                continue;
            }

            $parsed = $this->parseDocComments($statement->getComments());

            if ($parsed === []) {
                continue;
            }

            $call = (new NodeFinder)->findFirst($statement->expr, function (Node $node) use ($requestNames): bool {
                return $node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && $this->isRequestExpression($node->var, $requestNames)
                    && in_array(strtolower($node->name->toString()), $this->requestAccessorMethods(), true);
            });

            if (! $call instanceof Node\Expr\MethodCall) {
                continue;
            }

            $name = $this->stringArgument($call->args, 0);

            if ($name !== null && $name !== '') {
                $docs[$name] = array_replace($docs[$name] ?? [], $parsed);
            }
        }

        return $docs;
    }

    /**
     * @param  array<int, Comment>  $comments
     * @return array<string, mixed>
     */
    private function parseDocComments(array $comments): array
    {
        $text = trim(implode("\n", array_map(fn ($comment): string => $comment->getText(), $comments)));

        if ($text === '') {
            return [];
        }

        $text = (string) preg_replace('#^/\*\*?#', '', $text);
        $text = (string) preg_replace('#\*/$#', '', $text);
        $lines = array_map(
            fn (string $line): string => trim((string) preg_replace('#^\s*\*\s?#', '', $line)),
            preg_split('/\R/', $text) ?: [],
        );

        $docs = [];
        $description = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^@var\s+(\S+)/', $line, $matches) === 1) {
                $docs['type'] = $matches[1];
            } elseif (preg_match('/^@example\s+(.+)/', $line, $matches) === 1) {
                $docs['example'] = $this->docValue($matches[1]);
            } elseif (preg_match('/^@default\s+(.+)/', $line, $matches) === 1) {
                $docs['default'] = $this->docValue($matches[1]);
            } elseif (preg_match('/^@(query|body)$/', $line, $matches) === 1) {
                $docs['location'] = $matches[1];
            } elseif (preg_match('/^@ignoreParam$/i', $line) === 1) {
                $docs['ignore'] = true;
            } elseif (preg_match('/^@description\s+(.+)/', $line, $matches) === 1) {
                $description[] = $matches[1];
            } elseif (! str_starts_with($line, '@')) {
                $description[] = $line;
            }
        }

        if ($description !== []) {
            $docs['description'] = implode(' ', $description);
        }

        return $docs;
    }

    private function docValue(string $value): mixed
    {
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $lower = strtolower($value);

        return match (true) {
            $lower === 'true' => true,
            $lower === 'false' => false,
            $lower === 'null' => null,
            is_numeric($value) && str_contains($value, '.') => (float) $value,
            is_numeric($value) => (int) $value,
            default => $value,
        };
    }

    private function docKeyForField(string $field): string
    {
        $current = '';
        $escaped = false;
        $length = strlen($field);

        for ($i = 0; $i < $length; $i++) {
            $char = $field[$i];

            if ($escaped) {
                $current .= $char;
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === '.') {
                return $current;
            }

            $current .= $char;
        }

        return $current;
    }
}
