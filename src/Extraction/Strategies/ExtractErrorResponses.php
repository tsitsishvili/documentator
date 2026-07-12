<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Tsitsishvili\Documentator\Attributes\Authenticated;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ResponseData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\Extraction\Support\InlineValidationRulesExtractor;
use Tsitsishvili\Documentator\Extraction\Support\RouteActionReflection;
use Tsitsishvili\Documentator\Extraction\Support\SourceAnalyzer;

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
    public function __construct(
        private readonly InlineValidationRulesExtractor $inlineValidation,
        private readonly SourceAnalyzer $source,
    ) {}

    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        if (! config('documentator.error_responses', true)) {
            return $endpoint;
        }

        $action = RouteActionReflection::for($route, $method);

        if ($endpoint->authenticated || ($action !== null && $this->hasAuthAttribute($action))) {
            $endpoint->responses[401] ??= $this->messageResponse(401, 'Unauthenticated');
        }

        if ($action === null) {
            return $endpoint;
        }

        foreach ($this->controlFlowStatuses($action) as $status) {
            $endpoint->responses[$status] ??= $this->messageResponse($status, $this->description($status));
        }

        $formRequest = $this->findFormRequest($action);

        if ($formRequest !== null || $this->inlineValidation->rulesFor($action) !== []) {
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

        if ($this->bindsModel($endpoint, $action)) {
            $endpoint->responses[404] ??= $this->messageResponse(404, 'Not found');
        }

        return $endpoint;
    }

    private function hasAuthAttribute(ReflectionFunctionAbstract $action): bool
    {
        if ($action->getAttributes(Authenticated::class) !== []) {
            return true;
        }

        return $action instanceof ReflectionMethod
            && $action->getDeclaringClass()->getAttributes(Authenticated::class) !== [];
    }

    private function findFormRequest(ReflectionFunctionAbstract $action): ?string
    {
        foreach ($action->getParameters() as $parameter) {
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
        return $this->source->methodNode($method);
    }

    /** @return array<int, int> */
    private function controlFlowStatuses(ReflectionFunctionAbstract $action): array
    {
        $node = $this->source->functionLikeNode($action);

        if ($node === null) {
            return [];
        }

        $statuses = [];

        foreach ((new NodeFinder)->findInstanceOf($node, Node\Expr\FuncCall::class) as $call) {
            if (! $call instanceof Node\Expr\FuncCall || ! $call->name instanceof Node\Name) {
                continue;
            }

            $name = strtolower($call->name->getLast());
            $index = $name === 'abort' ? 0 : (in_array($name, ['abort_if', 'abort_unless'], true) ? 1 : null);

            if ($index !== null && ($status = $this->statusValue($call->args[$index]->value ?? null)) !== null) {
                $statuses[] = $status;
            }
        }

        foreach ((new NodeFinder)->find($node, fn (Node $candidate): bool => $candidate instanceof Node\Expr\MethodCall || $candidate instanceof Node\Expr\StaticCall) as $call) {
            if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) {
                continue;
            }

            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $method = strtolower($call->name->toString());

            if ($call instanceof Node\Expr\MethodCall
                && $call->var instanceof Node\Expr\Variable
                && $call->var->name === 'this'
                && in_array($method, ['authorize', 'authorizeforuser'], true)) {
                $statuses[] = 403;
            }

            if ($call instanceof Node\Expr\StaticCall
                && $call->class instanceof Node\Name
                && strtolower($call->class->getLast()) === 'gate'
                && in_array($method, ['authorize', 'allowif', 'denyif'], true)) {
                $statuses[] = 403;
            }
        }

        foreach ((new NodeFinder)->findInstanceOf($node, Node\Expr\Throw_::class) as $throw) {
            if ($throw instanceof Node\Expr\Throw_ && ($status = $this->thrownStatus($throw->expr)) !== null) {
                $statuses[] = $status;
            }
        }

        return array_values(array_unique(array_filter(
            $statuses,
            static fn (int $status): bool => $status >= 400 && $status <= 599,
        )));
    }

    private function thrownStatus(Node\Expr $expr): ?int
    {
        if ($expr instanceof Node\Expr\MethodCall) {
            if ($expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'asnotfound') {
                return 404;
            }

            $expr = $expr->var;
        }

        if (! $expr instanceof Node\Expr\New_ || ! $expr->class instanceof Node\Name) {
            return null;
        }

        $class = $expr->class->toString();
        $short = strtolower($expr->class->getLast());

        if ($short === 'httpexception') {
            return $this->statusValue($expr->args[0]->value ?? null);
        }

        $known = [
            'badrequesthttpexception' => 400,
            'authenticationexception' => 401,
            'unauthorizedhttpexception' => 401,
            'authorizationexception' => 403,
            'accessdeniedhttpexception' => 403,
            'notfoundhttpexception' => 404,
            'modelnotfoundexception' => 404,
            'methodnotallowedhttpexception' => 405,
            'notacceptablehttpexception' => 406,
            'requesttimeouthttpexception' => 408,
            'conflicthttpexception' => 409,
            'gonehttpexception' => 410,
            'lengthrequiredhttpexception' => 411,
            'preconditionfailedhttpexception' => 412,
            'payloadtoolargehttpexception' => 413,
            'unsupportedmediatypehttpexception' => 415,
            'validationexception' => 422,
            'unprocessableentityhttpexception' => 422,
            'lockedhttpexception' => 423,
            'preconditionrequiredhttpexception' => 428,
            'toomanyrequestshttpexception' => 429,
            'serviceunavailablehttpexception' => 503,
        ];

        if (isset($known[$short])) {
            return $known[$short];
        }

        try {
            if (is_a($class, 'Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface', true)) {
                return $this->statusValue($expr->args[0]->value ?? null);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
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

    private function description(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            406 => 'Not acceptable',
            408 => 'Request timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length required',
            412 => 'Precondition failed',
            413 => 'Payload too large',
            415 => 'Unsupported media type',
            422 => 'Validation error',
            423 => 'Locked',
            428 => 'Precondition required',
            429 => 'Too many requests',
            503 => 'Service unavailable',
            default => "HTTP {$status} error",
        };
    }

    /**
     * Whether a route parameter is resolved through implicit model binding: a
     * controller argument typed as a Model whose name matches a path parameter.
     */
    private function bindsModel(EndpointData $endpoint, ReflectionFunctionAbstract $action): bool
    {
        foreach ($action->getParameters() as $parameter) {
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
