<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ResponseData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\Extraction\Support\SourceAnalyzer;

/**
 * Infers success responses from literal inline JSON returns, such as
 * `return response()->json([...], 202)`. Dynamic response builders are skipped;
 * attributes and richer return-type inference still win through pipeline order.
 */
final class ExtractInlineResponses implements ExtractionStrategy
{
    public function __construct(private readonly SourceAnalyzer $source) {}

    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        if ($method === null) {
            return $endpoint;
        }

        foreach ($this->responsesFor($method) as $response) {
            $endpoint->responses[$response->status] ??= $response;
        }

        return $endpoint;
    }

    /**
     * @return array<int, ResponseData>
     */
    private function responsesFor(ReflectionMethod $method): array
    {
        $methodNode = $this->source->methodNode($method);

        if (! $methodNode instanceof Node\Stmt\ClassMethod) {
            return [];
        }

        $responses = [];

        $services = $this->serviceVariables($method);
        $variables = $this->variableSchemas($methodNode, $services);

        foreach ((new NodeFinder)->find($methodNode, fn (Node $node) => $node instanceof Node\Stmt\Return_) as $return) {
            if (! $return instanceof Node\Stmt\Return_ || ! $return->expr instanceof Node\Expr) {
                continue;
            }

            $response = $this->responseFromReturn($return->expr, $variables, $services);

            if ($response !== null) {
                $responses[] = $response;
            }
        }

        return $responses;
    }

    /**
     * @param  array<string, array<string, mixed>>  $variables
     * @param  array<string, class-string>  $services
     */
    private function responseFromReturn(Node\Expr $expr, array $variables = [], array $services = []): ?ResponseData
    {
        if (($schema = $this->schemaForExpression($expr, $variables, $services)) !== null) {
            return new ResponseData(
                status: 200,
                description: $this->describe(200),
                schema: $schema,
            );
        }

        if ($expr instanceof Node\Expr\New_ && $this->isJsonResponseClass($expr->class)) {
            return $this->jsonResponse($expr->args, 0, 1, $variables, $services);
        }

        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            return $this->functionResponse($expr, $variables, $services);
        }

        if (! $expr instanceof Node\Expr\MethodCall || ! $expr->name instanceof Node\Identifier) {
            return null;
        }

        $method = strtolower($expr->name->toString());

        if ($method === 'json' && $expr->var instanceof Node\Expr\FuncCall && $this->isNamedCall($expr->var, 'response')) {
            return $this->jsonResponse($expr->args, 0, 1, $variables, $services);
        }

        if ($method === 'make' && $expr->var instanceof Node\Expr\FuncCall && $this->isNamedCall($expr->var, 'response')) {
            return $this->contentResponse($expr->args, 0, 1, $variables, $services);
        }

        if ($method === 'view' && $expr->var instanceof Node\Expr\FuncCall && $this->isNamedCall($expr->var, 'response')) {
            return new ResponseData(
                status: $this->statusFromArgs($expr->args, 2) ?? 200,
                description: $this->describe($this->statusFromArgs($expr->args, 2) ?? 200),
                schema: ['type' => 'string'],
                mediaType: 'text/html',
            );
        }

        if ($method === 'nocontent' && $expr->var instanceof Node\Expr\FuncCall && $this->isNamedCall($expr->var, 'response')) {
            $status = $this->statusFromArgs($expr->args, 0) ?? 204;

            return new ResponseData(
                status: $status,
                description: $this->describe($status),
            );
        }

        if ($this->isRedirectCall($expr)) {
            $status = $this->statusFromArgs($expr->args, 1) ?? 302;

            return new ResponseData(
                status: $status,
                description: $this->describe($status),
            );
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $variables
     * @param  array<string, class-string>  $services
     */
    private function functionResponse(Node\Expr\FuncCall $expr, array $variables, array $services): ?ResponseData
    {
        $name = strtolower($expr->name->toString());

        if ($name === 'response') {
            return $this->contentResponse($expr->args, 0, 1, $variables, $services);
        }

        if ($name === 'json') {
            return $this->jsonResponse($expr->args, 0, 1, $variables, $services);
        }

        if ($name === 'view') {
            $status = $this->statusFromArgs($expr->args, 2) ?? 200;

            return new ResponseData(
                status: $status,
                description: $this->describe($status),
                schema: ['type' => 'string'],
                mediaType: 'text/html',
            );
        }

        if (in_array($name, ['redirect', 'back', 'to_route'], true)) {
            $status = $this->statusFromArgs($expr->args, $name === 'to_route' ? 2 : 1) ?? 302;

            return new ResponseData(
                status: $status,
                description: $this->describe($status),
            );
        }

        if ($name === 'abort') {
            $status = $this->statusFromArgs($expr->args, 0) ?? 500;

            return new ResponseData(
                status: $status,
                description: $this->describe($status),
            );
        }

        return null;
    }

    /**
     * @param  array<int, Node\Arg>  $args
     * @param  array<string, array<string, mixed>>  $variables
     * @param  array<string, class-string>  $services
     */
    private function jsonResponse(array $args, int $bodyIndex, int $statusIndex, array $variables = [], array $services = []): ?ResponseData
    {
        $body = $this->argValue($args, $bodyIndex, 'data');
        $schema = $body instanceof Node\Expr
            ? $this->schemaForExpression($body, $variables, $services)
            : null;

        if ($schema === null) {
            return null;
        }

        $status = $this->statusFromArgs($args, $statusIndex) ?? 200;

        return new ResponseData(
            status: $status,
            description: $this->describe($status),
            schema: $schema,
        );
    }

    /**
     * @param  array<int, Node\Arg>  $args
     * @param  array<string, array<string, mixed>>  $variables
     * @param  array<string, class-string>  $services
     */
    private function contentResponse(array $args, int $bodyIndex, int $statusIndex, array $variables = [], array $services = []): ?ResponseData
    {
        $body = $this->argValue($args, $bodyIndex, 'content');
        $status = $this->statusFromArgs($args, $statusIndex) ?? 200;

        if ($body === null) {
            return new ResponseData(
                status: $status,
                description: $this->describe($status),
            );
        }

        if (($schema = $this->schemaForExpression($body, $variables, $services)) !== null) {
            return new ResponseData(
                status: $status,
                description: $this->describe($status),
                schema: $schema,
            );
        }

        if ($body instanceof Node\Scalar\String_) {
            return new ResponseData(
                status: $status,
                description: $this->describe($status),
                schema: ['type' => 'string'],
                mediaType: 'text/plain',
            );
        }

        return null;
    }

    /**
     * @param  array<int, Node\Arg>  $args
     */
    private function statusFromArgs(array $args, int $index): ?int
    {
        return $this->statusValue($this->argValue($args, $index, 'status'));
    }

    /**
     * @param  array<int, Node\Arg>  $args
     */
    private function argValue(array $args, int $index, string $name): ?Node\Expr
    {
        foreach ($args as $arg) {
            if ($arg->name !== null && $arg->name->toString() === $name) {
                return $arg->value;
            }
        }

        return $args[$index]->value ?? null;
    }

    private function statusValue(?Node\Expr $expr): ?int
    {
        if ($expr instanceof Node\Scalar\Int_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier) {
            $constant = ltrim($expr->class->toString(), '\\').'::'.$expr->name->toString();

            if (defined($constant)) {
                $value = constant($constant);

                return is_int($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $variables
     * @param  array<string, class-string>  $services
     * @return array<string, mixed>|null
     */
    private function schemaForExpression(Node\Expr $expr, array $variables = [], array $services = []): ?array
    {
        if ($expr instanceof Node\Expr\Array_) {
            return $this->schemaForArray($expr);
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return $variables[$expr->name] ?? null;
        }

        if ($expr instanceof Node\Expr\MethodCall) {
            return $this->serviceCallSchema($expr, $services);
        }

        if ($expr instanceof Node\Expr\StaticCall) {
            return $this->staticCallSchema($expr);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForArray(Node\Expr\Array_ $array): array
    {
        if ($this->isList($array)) {
            return [
                'type' => 'array',
                'items' => $this->schemaForListItems($array),
            ];
        }

        $properties = [];

        foreach ($array->items as $item) {
            if (! $item instanceof Node\ArrayItem || ! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $name = $item->key->value;
            $properties[$name] = $this->schemaForValue($item->value, $name);
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    private function schemaForListItems(Node\Expr\Array_ $array): array
    {
        foreach ($array->items as $item) {
            if ($item instanceof Node\ArrayItem) {
                return $this->schemaForValue($item->value);
            }
        }

        return ['type' => 'string'];
    }

    private function schemaForValue(Node\Expr $expr, ?string $name = null): array
    {
        return match (true) {
            $expr instanceof Node\Scalar\Int_ => ['type' => 'integer'],
            $expr instanceof Node\Scalar\Float_ => ['type' => 'number'],
            $expr instanceof Node\Scalar\String_ => $this->stringSchema($expr->value, $name),
            $expr instanceof Node\Expr\ConstFetch && in_array(strtolower($expr->name->toString()), ['true', 'false'], true) => ['type' => 'boolean'],
            $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'null' => $this->nullableSchema($name),
            $expr instanceof Node\Expr\Array_ => $this->schemaForArray($expr),
            default => $this->fallbackSchema($name),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function stringSchema(string $value, ?string $name): array
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['type' => 'string', 'format' => 'email'];
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return ['type' => 'string', 'format' => 'uri'];
        }

        if (preg_match('/^[0-9a-fA-F-]{36}$/', $value) === 1) {
            return ['type' => 'string', 'format' => 'uuid'];
        }

        return $this->fallbackSchema($name);
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableSchema(?string $name): array
    {
        $schema = $this->fallbackSchema($name);
        $schema['nullable'] = true;

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackSchema(?string $name): array
    {
        if ($name === null) {
            return ['type' => 'string'];
        }

        return match (true) {
            str_ends_with($name, '_id') || $name === 'id' => ['type' => 'integer'],
            str_ends_with($name, '_at') => ['type' => 'string', 'format' => 'date-time'],
            str_starts_with($name, 'is_'), str_starts_with($name, 'has_') => ['type' => 'boolean'],
            $name === 'email' || str_ends_with($name, '_email') => ['type' => 'string', 'format' => 'email'],
            default => ['type' => 'string'],
        };
    }

    private function isList(Node\Expr\Array_ $array): bool
    {
        foreach ($array->items as $item) {
            if ($item instanceof Node\ArrayItem && $item->key instanceof Node\Scalar\String_) {
                return false;
            }
        }

        return true;
    }

    private function isJsonResponseClass(Node\Name|Node\Expr $class): bool
    {
        return $class instanceof Node\Name && is_a(ltrim($class->toString(), '\\'), JsonResponse::class, true);
    }

    private function isNamedCall(Node\Expr\FuncCall $call, string $name): bool
    {
        return $call->name instanceof Node\Name && strtolower($call->name->toString()) === $name;
    }

    private function isRedirectCall(Node\Expr\MethodCall $call): bool
    {
        if (! $call->name instanceof Node\Identifier) {
            return false;
        }

        $method = strtolower($call->name->toString());

        return in_array($method, ['route', 'to', 'away', 'secure', 'action', 'guest', 'intended'], true)
            && $call->var instanceof Node\Expr\FuncCall
            && $this->isNamedCall($call->var, 'redirect');
    }

    /**
     * @return array<string, class-string>
     */
    private function serviceVariables(ReflectionMethod $method): array
    {
        $services = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (class_exists($class)) {
                $services[$parameter->getName()] = $class;
            }
        }

        return $services;
    }

    /**
     * @param  array<string, class-string>  $services
     * @return array<string, array<string, mixed>>
     */
    private function variableSchemas(Node\Stmt\ClassMethod $methodNode, array $services): array
    {
        $variables = [];

        foreach ((new NodeFinder)->find($methodNode, fn (Node $node) => $node instanceof Node\Expr\Assign) as $assign) {
            if (! $assign instanceof Node\Expr\Assign
                || ! $assign->var instanceof Node\Expr\Variable
                || ! is_string($assign->var->name)
                || ! $assign->expr instanceof Node\Expr) {
                continue;
            }

            $schema = $this->schemaForExpression($assign->expr, $variables, $services);

            if ($schema !== null) {
                $variables[$assign->var->name] = $schema;
            }
        }

        return $variables;
    }

    /**
     * @param  array<string, class-string>  $services
     * @return array<string, mixed>|null
     */
    private function serviceCallSchema(Node\Expr\MethodCall $call, array $services): ?array
    {
        if (! $call->var instanceof Node\Expr\Variable
            || ! is_string($call->var->name)
            || ! $call->name instanceof Node\Identifier
            || ! isset($services[$call->var->name])) {
            return null;
        }

        return $this->classMethodSchema($services[$call->var->name], $call->name->toString());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function staticCallSchema(Node\Expr\StaticCall $call): ?array
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return null;
        }

        return $this->classMethodSchema(ltrim($call->class->toString(), '\\'), $call->name->toString());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function classMethodSchema(string $class, string $method): ?array
    {
        if (! class_exists($class)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
            $returnType = $reflection->getReturnType();

            if ($returnType instanceof ReflectionNamedType && $returnType->getName() !== 'array') {
                return null;
            }

            $return = $this->source->firstReturnExpression($reflection);

            return $return instanceof Node\Expr\Array_ ? $this->schemaForArray($return) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function describe(int $status): string
    {
        return match ($status) {
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No content',
            301 => 'Moved permanently',
            302 => 'Found',
            303 => 'See other',
            307 => 'Temporary redirect',
            308 => 'Permanent redirect',
            400 => 'Bad request',
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Not found',
            409 => 'Conflict',
            422 => 'Validation error',
            500 => 'Server error',
            default => 'Successful response',
        };
    }
}
