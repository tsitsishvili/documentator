<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Routing\Route;
use ReflectionMethod;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\Extraction\Support\InlineValidationRulesExtractor;
use Tsitsishvili\Documentator\Extraction\Support\RuleParser;

/**
 * Infers parameters from inline validation calls such as
 * `$request->validate([...])`. Only literal validation arrays are extracted;
 * dynamic rule variables are skipped so generation remains best-effort.
 */
final class ExtractInlineValidationRules implements ExtractionStrategy
{
    public function __construct(private readonly InlineValidationRulesExtractor $rules) {}

    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        if ($method === null) {
            return $endpoint;
        }

        $rules = $this->rules->rulesFor($method);

        if ($rules === []) {
            return $endpoint;
        }

        // Keep parity with FormRequest extraction: GET/HEAD validation describes
        // the query string, while mutating verbs describe a request body.
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
}
