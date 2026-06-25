<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

use BackedEnum;
use DateTimeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Throwable;
use Tsitsishvili\Documentator\Data\ParameterData;

/**
 * Derives an OpenAPI schema from a spatie/laravel-data Data object by reflecting
 * its public typed properties — the typed equivalent of reading a Resource's
 * toArray(). Used for both Data request objects and Data return types. Degrades
 * to `{type: object}` when a property type can't be mapped; never throws.
 */
final class DataObjectSchema
{
    public const DATA_CLASS = 'Spatie\\LaravelData\\Data';

    private const COLLECTION_OF = 'Spatie\\LaravelData\\Attributes\\DataCollectionOf';

    private const MAX_DEPTH = 4;

    /**
     * The request parameters a Data object validates: one per public property.
     *
     * @return array<string, ParameterData>
     */
    public function parameters(string $dataClass): array
    {
        $params = [];

        foreach ($this->reflectProperties($dataClass) as $property) {
            $schema = $this->propertySchema($property, 0);
            $params[$property->getName()] = new ParameterData(
                name: $property->getName(),
                type: $schema['type'] ?? 'string',
                required: ! $this->isOptional($property),
                schema: $schema,
            );
        }

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    public function forClass(string $dataClass, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['type' => 'object'];
        }

        $properties = [];

        foreach ($this->reflectProperties($dataClass) as $property) {
            $properties[$property->getName()] = $this->propertySchema($property, $depth);
        }

        return $properties === [] ? ['type' => 'object'] : ['type' => 'object', 'properties' => $properties];
    }

    /**
     * @return array<int, ReflectionProperty>
     */
    private function reflectProperties(string $dataClass): array
    {
        try {
            $properties = (new ReflectionClass($dataClass))->getProperties(ReflectionProperty::IS_PUBLIC);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(
            $properties,
            fn (ReflectionProperty $property) => ! $property->isStatic(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function propertySchema(ReflectionProperty $property, int $depth): array
    {
        $type = $property->getType();

        if ($type instanceof ReflectionUnionType) {
            $type = $this->firstNamedType($type);
        }

        $schema = $type instanceof ReflectionNamedType
            ? $this->typeSchema($type->getName(), $property, $depth)
            : ['type' => 'string'];

        if ($type !== null && $type->allowsNull()) {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function typeSchema(string $name, ReflectionProperty $property, int $depth): array
    {
        $builtin = match ($name) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'string' => ['type' => 'string'],
            'array', 'iterable' => $this->collectionSchema($property, $depth),
            'mixed' => ['type' => 'object'],
            default => null,
        };

        if ($builtin !== null) {
            return $builtin;
        }

        if (is_subclass_of($name, BackedEnum::class)) {
            return $this->enumSchema($name);
        }

        if (is_subclass_of($name, DateTimeInterface::class)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if (is_subclass_of($name, self::DATA_CLASS)) {
            return $this->forClass($name, $depth + 1);
        }

        // A DataCollection property carries its item type in #[DataCollectionOf].
        return $this->collectionSchema($property, $depth);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionSchema(ReflectionProperty $property, int $depth): array
    {
        foreach ($property->getAttributes(self::COLLECTION_OF) as $attribute) {
            $arguments = $attribute->getArguments();
            $item = $arguments[0] ?? ($arguments['class'] ?? null);

            if (is_string($item) && is_subclass_of($item, self::DATA_CLASS)) {
                return ['type' => 'array', 'items' => $this->forClass($item, $depth + 1)];
            }
        }

        return ['type' => 'array', 'items' => ['type' => 'object']];
    }

    /**
     * @return array<string, mixed>
     */
    private function enumSchema(string $enum): array
    {
        $cases = array_map(fn (BackedEnum $case) => $case->value, $enum::cases());
        $type = $cases !== [] && is_int($cases[0]) ? 'integer' : 'string';

        return ['type' => $type, 'enum' => $cases];
    }

    private function firstNamedType(ReflectionUnionType $union): ?ReflectionNamedType
    {
        foreach ($union->getTypes() as $type) {
            if ($type instanceof ReflectionNamedType && $type->getName() !== 'null') {
                return $type;
            }
        }

        return null;
    }

    /**
     * A field is optional when its type is nullable or it has a default value —
     * for a constructor-promoted property the default lives on the parameter.
     */
    private function isOptional(ReflectionProperty $property): bool
    {
        $type = $property->getType();

        if ($type !== null && $type->allowsNull()) {
            return true;
        }

        if ($property->hasDefaultValue()) {
            return true;
        }

        if (! $property->isPromoted()) {
            return false;
        }

        foreach ($property->getDeclaringClass()->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $property->getName()) {
                return $parameter->isDefaultValueAvailable();
            }
        }

        return false;
    }
}
