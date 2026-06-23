<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
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
        if ($method === null) {
            return $endpoint;
        }

        $formRequest = $this->findFormRequest($method);

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

        foreach (RuleParser::parse($rules) as $param) {
            $endpoint->bodyParameters[$param->name] ??= $param;
        }

        return $endpoint;
    }

    private function findFormRequest(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
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
