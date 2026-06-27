<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionMethod;
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

        foreach ((new NodeFinder)->find($methodNode, fn (Node $node) => $node instanceof Node\Stmt\Return_) as $return) {
            if (! $return instanceof Node\Stmt\Return_ || ! $return->expr instanceof Node\Expr) {
                continue;
            }

            $response = $this->responseFromReturn($return->expr);

            if ($response !== null) {
                $responses[] = $response;
            }
        }

        return $responses;
    }

    private function responseFromReturn(Node\Expr $expr): ?ResponseData
    {
        if ($expr instanceof Node\Expr\Array_) {
            return new ResponseData(
                status: 200,
                description: $this->describe(200),
                schema: $this->schemaForArray($expr),
            );
        }

        if ($expr instanceof Node\Expr\New_ && $this->isJsonResponseClass($expr->class)) {
            return $this->jsonResponse($expr->args, 0, 1);
        }

        if (! $expr instanceof Node\Expr\MethodCall || ! $expr->name instanceof Node\Identifier) {
            return null;
        }

        $method = strtolower($expr->name->toString());

        if ($method === 'json' && $expr->var instanceof Node\Expr\FuncCall && $this->isNamedCall($expr->var, 'response')) {
            return $this->jsonResponse($expr->args, 0, 1);
        }

        if ($method === 'nocontent' && $expr->var instanceof Node\Expr\FuncCall && $this->isNamedCall($expr->var, 'response')) {
            $status = $this->statusFromArgs($expr->args, 0) ?? 204;

            return new ResponseData(
                status: $status,
                description: $this->describe($status),
            );
        }

        return null;
    }

    /**
     * @param  array<int, Node\Arg>  $args
     */
    private function jsonResponse(array $args, int $bodyIndex, int $statusIndex): ?ResponseData
    {
        $body = $this->argValue($args, $bodyIndex, 'data');

        if (! $body instanceof Node\Expr\Array_) {
            return null;
        }

        $status = $this->statusFromArgs($args, $statusIndex) ?? 200;

        return new ResponseData(
            status: $status,
            description: $this->describe($status),
            schema: $this->schemaForArray($body),
        );
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

    private function describe(int $status): string
    {
        return match ($status) {
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No content',
            default => 'Successful response',
        };
    }
}
