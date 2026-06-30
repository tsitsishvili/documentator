<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\Extraction\Support\RouteActionReflection;
use Tsitsishvili\Documentator\Extraction\Support\RuleParser;

/**
 * Infers body parameters from a FormRequest type-hinted in the controller
 * signature by parsing its rules(). Values already present are left untouched
 * so a later #[BodyParam] override always wins.
 */
final class ExtractFormRequestRules implements ExtractionStrategy
{
    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        $action = RouteActionReflection::for($route, $method);

        if ($action === null) {
            return $endpoint;
        }

        $formRequest = $this->findFormRequest($action);

        if ($formRequest === null) {
            return $endpoint;
        }

        try {
            // Instantiate directly rather than via the container: resolving a
            // FormRequest through the container fires validateResolved(), which
            // would throw because there's no real request to validate.
            $instance = new $formRequest;
            $rules = method_exists($instance, 'rules') ? (array) $instance->rules() : [];
        } catch (Throwable) {
            // A FormRequest whose rules() depends on request/route state can't be
            // evaluated statically; skip it rather than break generation.
            return $endpoint;
        }

        // GET/HEAD requests carry no body: their validated input arrives as query
        // string parameters, so document it there rather than as a request body
        // (which OpenAPI clients ignore for these verbs).
        $verbs = $endpoint->verbs();
        $readOnly = $verbs !== [] && array_diff($verbs, ['get', 'head']) === [];

        foreach (RuleParser::parse($rules) as $param) {
            if ($readOnly) {
                $endpoint->queryParameters[$param->name] ??= $param;
            } else {
                $endpoint->bodyParameters[$param->name] ??= $param;
            }
        }

        return $endpoint;
    }

    private function findFormRequest(ReflectionFunctionAbstract $action): ?string
    {
        foreach ($action->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (is_subclass_of($class, FormRequest::class)) {
                return $class;
            }
        }

        return null;
    }
}
