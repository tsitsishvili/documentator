<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Tsitsishvili\Documentator\Attributes\Authenticated;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ResponseData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\Extraction\Support\InlineValidationRulesExtractor;

/**
 * Adds the conventional error responses an endpoint can return without anyone
 * declaring them, inferred from the shape the other strategies already found:
 *
 *   401  the endpoint requires authentication (auth middleware / #[Authenticated])
 *   403  a type-hinted FormRequest overrides authorize() with a body that can
 *        actually deny (a trivial `return true` override is not a real gate)
 *   404  the route binds a model (implicit route-model binding)
 *   422  a type-hinted FormRequest or inline validation validates input
 *
 * Each is keyed by status and added with `??=`, so it never clobbers a richer
 * response from another strategy and a later #[Response] always wins. Disable
 * the whole behaviour with config('documentator.error_responses').
 */
final class ExtractErrorResponses implements ExtractionStrategy
{
    private readonly Parser $parser;

    public function __construct(private readonly InlineValidationRulesExtractor $inlineValidation)
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        if (! config('documentator.error_responses', true)) {
            return $endpoint;
        }

        if ($endpoint->authenticated || ($method !== null && $this->hasAuthAttribute($method))) {
            $endpoint->responses[401] ??= $this->messageResponse(401, 'Unauthenticated');
        }

        if ($method === null) {
            return $endpoint;
        }

        $formRequest = $this->findFormRequest($method);

        if ($formRequest !== null || $this->inlineValidation->rulesFor($method) !== []) {
            $endpoint->responses[422] ??= new ResponseData(
                status: 422,
                description: 'Validation error',
                schema: $this->validationSchema(),
            );
        }

        if ($formRequest !== null) {
            if ($this->authorizes($formRequest)) {
                $endpoint->responses[403] ??= $this->messageResponse(403, 'Forbidden');
            }
        }

        if ($this->bindsModel($endpoint, $method)) {
            $endpoint->responses[404] ??= $this->messageResponse(404, 'Not found');
        }

        return $endpoint;
    }

    private function hasAuthAttribute(ReflectionMethod $method): bool
    {
        return $method->getAttributes(Authenticated::class) !== []
            || $method->getDeclaringClass()->getAttributes(Authenticated::class) !== [];
    }

    private function findFormRequest(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if (is_subclass_of($type->getName(), FormRequest::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * Whether the FormRequest declares an authorize() that can actually deny the
     * request. The base class has no authorize(), so an un-overridden one is no
     * gate; an override whose body is just `return true` (the make:request
     * default) can never fail either, so neither produces a 403.
     */
    private function authorizes(string $formRequest): bool
    {
        try {
            $method = new ReflectionMethod($formRequest, 'authorize');
        } catch (Throwable) {
            return false;
        }

        if ($method->getDeclaringClass()->getName() === FormRequest::class) {
            return false;
        }

        return ! $this->alwaysAuthorizes($method);
    }

    /**
     * Whether authorize() can only ever return true: a literal `true` return type
     * or a single `return true;` statement. Conservative — anything else (any
     * branching, a non-trivial body, an unreadable source) is treated as a real
     * gate that may produce a 403.
     */
    private function alwaysAuthorizes(ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType && $returnType->getName() === 'true') {
            return true;
        }

        $node = $this->methodNode($method);

        if ($node === null || $node->stmts === null) {
            return false;
        }

        return count($node->stmts) === 1
            && $node->stmts[0] instanceof Node\Stmt\Return_
            && $this->isTrue($node->stmts[0]->expr);
    }

    private function isTrue(?Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\ConstFetch
            && strtolower($expr->name->toString()) === 'true';
    }

    private function methodNode(ReflectionMethod $method): ?Node\Stmt\ClassMethod
    {
        $file = $method->getFileName();

        if ($file === false) {
            return null;
        }

        try {
            $ast = $this->parser->parse((string) file_get_contents($file));
        } catch (Throwable) {
            return null;
        }

        if ($ast === null) {
            return null;
        }

        $node = (new NodeFinder)->findFirst(
            $ast,
            fn (Node $node) => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === $method->getName()
                && $node->getStartLine() === $method->getStartLine(),
        );

        return $node instanceof Node\Stmt\ClassMethod ? $node : null;
    }

    /**
     * Whether a route parameter is resolved through implicit model binding: a
     * controller argument typed as a Model whose name matches a path parameter.
     */
    private function bindsModel(EndpointData $endpoint, ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if (is_subclass_of($type->getName(), Model::class)
                && isset($endpoint->pathParameters[$parameter->getName()])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The body Laravel returns for a failed validation: a message plus a map of
     * field => messages.
     *
     * @return array<string, mixed>
     */
    private function validationSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ];
    }

    private function messageResponse(int $status, string $description): ResponseData
    {
        return new ResponseData(
            status: $status,
            description: $description,
            schema: ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
        );
    }
}
