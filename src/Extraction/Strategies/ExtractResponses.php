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
use Tsitsishvili\Documentator\OpenApi\PaginationSchema;
use Tsitsishvili\Documentator\OpenApi\ResourceSchemaExtractor;

/**
 * Infers a success response from the controller's return type when it is an
 * API Resource, including its body schema. A ResourceCollection return type is
 * wrapped in the paginator envelope. A guaranteed fallback response is added by
 * the generator, so #[Response] attributes remain free to declare real codes.
 */
final class ExtractResponses implements ExtractionStrategy
{
    public function __construct(private readonly ResourceSchemaExtractor $schemas) {}

    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        if ($method === null) {
            return $endpoint;
        }

        $returnType = $method->getReturnType();

        if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return $endpoint;
        }

        $class = $returnType->getName();

        // A body-bearing success is 200 unless the verb conventionally creates
        // (POST -> 201); a 204 is reserved for the empty-body fallback.
        $status = in_array('post', $endpoint->verbs(), true) && $this->infersStatus() ? 201 : 200;

        if (is_subclass_of($class, ResourceCollection::class)) {
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $this->describe($status),
                resource: $class,
                schema: PaginationSchema::paginated($this->collectsSchema($class)),
            );

            foreach (PaginationSchema::queryParameters() as $name => $param) {
                $endpoint->queryParameters[$name] ??= $param;
            }
        } elseif (is_subclass_of($class, JsonResource::class)) {
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $this->describe($status),
                resource: $class,
                schema: $this->schemas->extract($class),
            );
        } elseif (is_subclass_of($class, Model::class)) {
            $endpoint->responses[$status] ??= new ResponseData(
                status: $status,
                description: $this->describe($status),
                schema: $this->schemas->extractModel($class),
            );
        }

        return $endpoint;
    }

    private function infersStatus(): bool
    {
        return (bool) config('documentator.infer_status_codes', true);
    }

    private function describe(int $status): string
    {
        return $status === 201 ? 'Created' : 'Successful response';
    }

    /**
     * The item schema a ResourceCollection collects, read from its $collects.
     *
     * @return array<string, mixed>
     */
    private function collectsSchema(string $collection): array
    {
        try {
            $collects = (new ReflectionClass($collection))->getDefaultProperties()['collects'] ?? null;
        } catch (Throwable) {
            $collects = null;
        }

        return is_string($collects) && is_subclass_of($collects, JsonResource::class)
            ? $this->schemas->extract($collects)
            : ['type' => 'object'];
    }
}
