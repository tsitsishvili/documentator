<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Routing\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ResponseData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\OpenApi\DataObjectSchema;

/**
 * Infers parameters and responses from spatie/laravel-data Data objects: a
 * Data-typed controller argument becomes body (or query, for GET/HEAD) params,
 * and a Data return type becomes a success response schema. No-ops when
 * spatie/laravel-data isn't installed. Fills gaps with `??=` so #[…] attributes
 * still override.
 */
final class ExtractDataObjects implements ExtractionStrategy
{
    public function __construct(private readonly DataObjectSchema $schemas) {}

    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        if ($method === null || ! class_exists(DataObjectSchema::DATA_CLASS)) {
            return $endpoint;
        }

        $this->request($endpoint, $method);
        $this->response($endpoint, $method);

        return $endpoint;
    }

    private function request(EndpointData $endpoint, ReflectionMethod $method): void
    {
        $dataClass = $this->findData($method);

        if ($dataClass === null) {
            return;
        }

        $verbs = $endpoint->verbs();
        $readOnly = $verbs !== [] && array_diff($verbs, ['get', 'head']) === [];

        foreach ($this->schemas->parameters($dataClass) as $name => $param) {
            if ($readOnly) {
                $endpoint->queryParameters[$name] ??= $param;
            } else {
                $endpoint->bodyParameters[$name] ??= $param;
            }
        }
    }

    private function response(EndpointData $endpoint, ReflectionMethod $method): void
    {
        $returnType = $method->getReturnType();

        if (! $returnType instanceof ReflectionNamedType
            || $returnType->isBuiltin()
            || ! is_subclass_of($returnType->getName(), DataObjectSchema::DATA_CLASS)) {
            return;
        }

        $status = in_array('post', $endpoint->verbs(), true) && config('documentator.infer_status_codes', true)
            ? 201
            : 200;

        $endpoint->responses[$status] ??= new ResponseData(
            status: $status,
            description: $status === 201 ? 'Created' : 'Successful response',
            schema: $this->schemas->forClass($returnType->getName()),
        );
    }

    private function findData(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if (is_subclass_of($type->getName(), DataObjectSchema::DATA_CLASS)) {
                return $type->getName();
            }
        }

        return null;
    }
}
