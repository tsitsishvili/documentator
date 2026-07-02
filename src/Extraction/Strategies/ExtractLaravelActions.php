<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ResponseData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;
use Tsitsishvili\Documentator\Extraction\Support\RuleParser;
use Tsitsishvili\Documentator\OpenApi\PaginationSchema;
use Tsitsishvili\Documentator\OpenApi\ResourceSchemaExtractor;

/**
 * Best-effort support for lorisleiva/laravel-actions controller routes. Action
 * classes commonly expose rules()/authorize() and return from handle(), while
 * the actual route points at asController().
 */
final class ExtractLaravelActions implements ExtractionStrategy
{
    public function __construct(private readonly ResourceSchemaExtractor $schemas) {}

    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        $class = $method?->class;

        if ($class === null || ! $this->looksLikeAction($class)) {
            return $endpoint;
        }

        $this->rules($endpoint, $class);
        $this->response($endpoint, $class);

        return $endpoint;
    }

    private function looksLikeAction(string $class): bool
    {
        if (! method_exists($class, 'handle')) {
            return false;
        }

        if (method_exists($class, 'asController') || method_exists($class, 'rules')) {
            return true;
        }

        try {
            foreach ((new ReflectionClass($class))->getTraitNames() as $trait) {
                if (str_ends_with($trait, '\\AsAction')) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private function rules(EndpointData $endpoint, string $class): void
    {
        if (! method_exists($class, 'rules')) {
            return;
        }

        try {
            $rules = (array) (new $class)->rules();
        } catch (Throwable) {
            return;
        }

        $verbs = $endpoint->verbs();
        $readOnly = $verbs !== [] && array_diff($verbs, ['get', 'head']) === [];

        foreach (RuleParser::parse($rules) as $param) {
            if ($readOnly) {
                $endpoint->queryParameters[$param->name] ??= $param;
            } else {
                $endpoint->bodyParameters[$param->name] ??= $param;
            }
        }
    }

    private function response(EndpointData $endpoint, string $class): void
    {
        try {
            $handle = new ReflectionMethod($class, 'handle');
        } catch (Throwable) {
            return;
        }

        $returnType = $handle->getReturnType();

        if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return;
        }

        $returnClass = $returnType->getName();
        $status = in_array('post', $endpoint->verbs(), true) && (bool) config('documentator.infer_status_codes', true) ? 201 : 200;

        if (is_subclass_of($returnClass, ResourceCollection::class)) {
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $status === 201 ? 'Created' : 'Successful response',
                resource: $returnClass,
                schema: PaginationSchema::paginated(['type' => 'object'], $returnClass),
                collection: $returnClass,
            );

            foreach (PaginationSchema::queryParameters() as $name => $param) {
                $endpoint->queryParameters[$name] ??= $param;
            }
        } elseif (is_subclass_of($returnClass, JsonResource::class)) {
            $jsonApi = $this->schemas->isJsonApiResource($returnClass);
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $status === 201 ? 'Created' : 'Successful response',
                resource: $returnClass,
                schema: $this->schemas->extract($returnClass),
                mediaType: $jsonApi ? 'application/vnd.api+json' : null,
            );
        } elseif (is_subclass_of($returnClass, Model::class)) {
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $status === 201 ? 'Created' : 'Successful response',
                schema: $this->schemas->extractModel($returnClass),
            );
        }
    }
}
