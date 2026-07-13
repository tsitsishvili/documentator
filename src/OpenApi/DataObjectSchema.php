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

    private const MAP_INPUT_NAME = 'Spatie\\LaravelData\\Attributes\\MapInputName';

    private const MAP_OUTPUT_NAME = 'Spatie\\LaravelData\\Attributes\\MapOutputName';

    private const NAME_MAPPER = 'Spatie\\LaravelData\\Mappers\\NameMapper';

    private const OPTIONAL = 'Spatie\\LaravelData\\Optional';

    private const LAZY = 'Spatie\\LaravelData\\Lazy';

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
            $name = $this->mappedName($property, self::MAP_INPUT_NAME);
            $params[$name] = new ParameterData(
                name: $name,
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
        $required = [];

        foreach ($this->reflectProperties($dataClass) as $property) {
            $name = $this->mappedName($property, self::MAP_OUTPUT_NAME);
            $properties[$name] = $this->propertySchema($property, $depth);

            if (! $this->isOptional($property)) {
                $required[] = $name;
            }
        }

        if ($properties === []) {
            return ['type' => 'object'];
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
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

        $nullable = $type?->allowsNull() ?? false;

        if ($type instanceof ReflectionUnionType) {
            $type = $this->firstDocumentedType($type);
        }

        $schema = $type instanceof ReflectionNamedType
            ? $this->typeSchema($type->getName(), $property, $depth)
            : ['type' => 'string'];

        if ($nullable) {
            $schema['nullable'] = true;
        }

        $description = $this->propertyDescription($property);

        if ($description !== null) {
            $schema['description'] = $description;
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

    private function firstDocumentedType(ReflectionUnionType $union): ?ReflectionNamedType
    {
        foreach ($union->getTypes() as $type) {
            if ($type instanceof ReflectionNamedType
                && ! in_array($type->getName(), ['null', self::OPTIONAL, self::LAZY], true)
                && ! is_subclass_of($type->getName(), self::LAZY)) {
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

        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : ($type instanceof ReflectionNamedType ? [$type] : []);

        foreach ($types as $candidate) {
            if (! $candidate instanceof ReflectionNamedType) {
                continue;
            }

            $name = $candidate->getName();

            if ($name === self::OPTIONAL || $name === self::LAZY || is_subclass_of($name, self::LAZY)) {
                return true;
            }
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

    private function mappedName(ReflectionProperty $property, string $attributeClass): string
    {
        try {
            $attributes = $property->getAttributes($attributeClass);

            if ($attributes === []) {
                $attributes = $property->getDeclaringClass()->getAttributes($attributeClass);
            }

            $attribute = $attributes[0] ?? null;
            $arguments = $attribute?->getArguments() ?? [];
            $mapper = $arguments[0] ?? $arguments['input'] ?? $arguments['output'] ?? null;

            if (is_string($mapper) && class_exists($mapper) && is_subclass_of($mapper, self::NAME_MAPPER)) {
                $mapper = (new $mapper)->map($property->getName());
            }

            return is_string($mapper) || is_int($mapper) ? (string) $mapper : $property->getName();
        } catch (Throwable) {
            return $property->getName();
        }
    }

    private function propertyDescription(ReflectionProperty $property): ?string
    {
        $doc = $property->getDocComment();

        if (! is_string($doc)) {
            return null;
        }

        $lines = preg_split('/\R/', $doc) ?: [];
        $description = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/^\s*\/?\**\s?|\*\/$/', '', $line) ?? '');

            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            $description[] = $line;
        }

        return $description === [] ? null : implode("\n", $description);
    }
}
