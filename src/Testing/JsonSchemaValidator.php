<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Testing;

use DateTimeImmutable;
use Throwable;

/**
 * Validates runtime values against the JSON Schema vocabulary Documentator
 * emits, keeping response-contract assertions dependency-free and predictable.
 */
final class JsonSchemaValidator
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<int, string>
     */
    public function validate(array $document, mixed $value, mixed $schema, string $path = 'body'): array
    {
        return $this->validateValue($document, $value, $schema, $path, 0);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, string>
     */
    private function validateValue(array $document, mixed $value, mixed $schema, string $path, int $depth): array
    {
        if ($depth > 64) {
            return ["{$path}: schema nesting exceeds the supported validation depth"];
        }

        if ($schema === true) {
            return [];
        }

        if ($schema === false) {
            return ["{$path}: value is rejected by the documented schema"];
        }

        if (! is_array($schema)) {
            return ["{$path}: documented schema is not an object or boolean"];
        }

        $errors = [];

        if (isset($schema['$ref'])) {
            $ref = (string) $schema['$ref'];
            $resolved = $this->resolvePointer($document, $ref);

            if (! is_array($resolved) && ! is_bool($resolved)) {
                return ["{$path}: documented schema reference cannot be resolved: {$ref}"];
            }

            $errors = array_merge(
                $errors,
                $this->validateValue($document, $value, $resolved, $path, $depth + 1),
            );

            unset($schema['$ref']);

            if ($schema === []) {
                return $errors;
            }
        }

        if (array_key_exists('const', $schema) && ! $this->equivalent($value, $schema['const'])) {
            $errors[] = "{$path}: value does not match the documented constant";
        }

        if (is_array($schema['enum'] ?? null)) {
            $enumMatch = false;

            foreach ($schema['enum'] as $allowed) {
                if ($this->equivalent($value, $allowed)) {
                    $enumMatch = true;
                    break;
                }
            }

            if (! $enumMatch) {
                $errors[] = "{$path}: value is not one of the documented enum values";
            }
        }

        foreach ((array) ($schema['allOf'] ?? []) as $branch) {
            $errors = array_merge(
                $errors,
                $this->validateValue($document, $value, $branch, $path, $depth + 1),
            );
        }

        if (is_array($schema['anyOf'] ?? null) && $schema['anyOf'] !== []) {
            $matches = array_filter(
                $schema['anyOf'],
                fn (mixed $branch): bool => $this->validateValue($document, $value, $branch, $path, $depth + 1) === [],
            );

            if ($matches === []) {
                $errors[] = "{$path}: value does not match any documented anyOf schema";
            }
        }

        if (is_array($schema['oneOf'] ?? null) && $schema['oneOf'] !== []) {
            $matches = array_filter(
                $schema['oneOf'],
                fn (mixed $branch): bool => $this->validateValue($document, $value, $branch, $path, $depth + 1) === [],
            );

            if (count($matches) === 0) {
                $errors[] = "{$path}: value does not match any documented oneOf schema";
            } elseif (count($matches) > 1) {
                $errors[] = "{$path}: value matches more than one documented oneOf schema";
            }
        }

        if (array_key_exists('not', $schema)
            && $this->validateValue($document, $value, $schema['not'], $path, $depth + 1) === []) {
            $errors[] = "{$path}: value matches a schema explicitly excluded by the documentation";
        }

        $types = $this->types($schema['type'] ?? null);

        if ($types !== []) {
            $typeMatch = false;

            foreach ($types as $type) {
                if ($this->matchesType($value, $type)) {
                    $typeMatch = true;
                    break;
                }
            }

            if (! $typeMatch) {
                $expected = implode('|', $types);
                $errors[] = "{$path}: expected {$expected}, got {$this->valueType($value)}";

                return $errors;
            }
        }

        if ($this->isObject($value)) {
            $errors = array_merge($errors, $this->validateObject($document, $value, $schema, $path, $depth));
        }

        if ($this->isArray($value)) {
            $errors = array_merge($errors, $this->validateArray($document, $value, $schema, $path, $depth));
        }

        if (is_string($value)) {
            $errors = array_merge($errors, $this->validateString($value, $schema, $path));
        }

        if (is_int($value) || is_float($value)) {
            $errors = array_merge($errors, $this->validateNumber($value, $schema, $path));
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function validateObject(array $document, mixed $value, array $schema, string $path, int $depth): array
    {
        $errors = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $values = is_object($value) ? get_object_vars($value) : $value;

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (is_string($required) && ! array_key_exists($required, $values)) {
                $errors[] = "{$path}: required property [{$required}] is missing";
            }
        }

        foreach ($properties as $name => $propertySchema) {
            if (is_string($name) && array_key_exists($name, $values)) {
                $errors = array_merge(
                    $errors,
                    $this->validateValue(
                        $document,
                        $values[$name],
                        $propertySchema,
                        $this->propertyPath($path, $name),
                        $depth + 1,
                    ),
                );
            }
        }

        $patternProperties = is_array($schema['patternProperties'] ?? null) ? $schema['patternProperties'] : [];

        foreach ($values as $name => $propertyValue) {
            if (array_key_exists($name, $properties)) {
                continue;
            }

            $matchedPattern = false;

            foreach ($patternProperties as $pattern => $propertySchema) {
                if (! is_string($pattern) || ! $this->patternMatches((string) $name, $pattern)) {
                    continue;
                }

                $matchedPattern = true;
                $errors = array_merge(
                    $errors,
                    $this->validateValue(
                        $document,
                        $propertyValue,
                        $propertySchema,
                        $this->propertyPath($path, (string) $name),
                        $depth + 1,
                    ),
                );
            }

            if ($matchedPattern) {
                continue;
            }

            $additional = $schema['additionalProperties'] ?? true;

            if ($additional === false) {
                $errors[] = $this->propertyPath($path, (string) $name).': property is not allowed by the documented schema';
            } elseif (is_array($additional) || is_bool($additional)) {
                $errors = array_merge(
                    $errors,
                    $this->validateValue(
                        $document,
                        $propertyValue,
                        $additional,
                        $this->propertyPath($path, (string) $name),
                        $depth + 1,
                    ),
                );
            }
        }

        if (isset($schema['minProperties']) && count($values) < (int) $schema['minProperties']) {
            $errors[] = "{$path}: expected at least {$schema['minProperties']} properties, got ".count($values);
        }

        if (isset($schema['maxProperties']) && count($values) > (int) $schema['maxProperties']) {
            $errors[] = "{$path}: expected at most {$schema['maxProperties']} properties, got ".count($values);
        }

        foreach ((array) ($schema['dependentRequired'] ?? []) as $name => $dependencies) {
            if (! array_key_exists($name, $values)) {
                continue;
            }

            foreach ((array) $dependencies as $dependency) {
                if (is_string($dependency) && ! array_key_exists($dependency, $values)) {
                    $errors[] = "{$path}: property [{$name}] requires property [{$dependency}]";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<int, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function validateArray(array $document, array $value, array $schema, string $path, int $depth): array
    {
        $errors = [];
        $count = count($value);

        if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
            $errors[] = "{$path}: expected at least {$schema['minItems']} items, got {$count}";
        }

        if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
            $errors[] = "{$path}: expected at most {$schema['maxItems']} items, got {$count}";
        }

        if (($schema['uniqueItems'] ?? false) === true) {
            for ($left = 0; $left < $count; $left++) {
                for ($right = $left + 1; $right < $count; $right++) {
                    if ($this->equivalent($value[$left], $value[$right])) {
                        $errors[] = "{$path}: array items must be unique";
                        break 2;
                    }
                }
            }
        }

        $prefixItems = is_array($schema['prefixItems'] ?? null) ? array_values($schema['prefixItems']) : [];

        foreach ($value as $index => $item) {
            $itemSchema = $prefixItems[$index] ?? ($schema['items'] ?? true);

            $errors = array_merge(
                $errors,
                $this->validateValue($document, $item, $itemSchema, "{$path}.{$index}", $depth + 1),
            );
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function validateString(string $value, array $schema, string $path): array
    {
        $errors = [];
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
            $errors[] = "{$path}: expected at least {$schema['minLength']} characters, got {$length}";
        }

        if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            $errors[] = "{$path}: expected at most {$schema['maxLength']} characters, got {$length}";
        }

        if (is_string($schema['pattern'] ?? null)) {
            $match = $this->pregMatch($value, $schema['pattern']);

            if ($match === false) {
                $errors[] = "{$path}: documented pattern is not a valid runtime regular expression";
            } elseif ($match !== 1) {
                $errors[] = "{$path}: string does not match the documented pattern";
            }
        }

        if (is_string($schema['format'] ?? null) && ! $this->matchesFormat($value, $schema['format'])) {
            $errors[] = "{$path}: string does not match the documented {$schema['format']} format";
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function validateNumber(int|float $value, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "{$path}: value must be at least {$schema['minimum']}";
        }

        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = "{$path}: value must be at most {$schema['maximum']}";
        }

        if (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
            $errors[] = "{$path}: value must be greater than {$schema['exclusiveMinimum']}";
        }

        if (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
            $errors[] = "{$path}: value must be less than {$schema['exclusiveMaximum']}";
        }

        if (isset($schema['multipleOf']) && is_numeric($schema['multipleOf']) && (float) $schema['multipleOf'] > 0) {
            $multiple = (float) $schema['multipleOf'];
            $remainder = fmod((float) $value, $multiple);

            if (abs($remainder) > 1e-10 && abs($remainder - $multiple) > 1e-10) {
                $errors[] = "{$path}: value must be a multiple of {$schema['multipleOf']}";
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function types(mixed $type): array
    {
        if (is_string($type)) {
            return [$type];
        }

        if (! is_array($type)) {
            return [];
        }

        return array_values(array_filter($type, is_string(...)));
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'array' => $this->isArray($value),
            'object' => $this->isObject($value),
            default => false,
        };
    }

    private function valueType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            $this->isArray($value) => 'array',
            $this->isObject($value) => 'object',
            default => get_debug_type($value),
        };
    }

    private function isArray(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private function isObject(mixed $value): bool
    {
        return is_object($value) || (is_array($value) && ! array_is_list($value));
    }

    private function propertyPath(string $path, string $property): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $property) === 1) {
            return "{$path}.{$property}";
        }

        return $path.'['.json_encode($property, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).']';
    }

    private function patternMatches(string $value, string $pattern): bool
    {
        return $this->pregMatch($value, $pattern) === 1;
    }

    private function pregMatch(string $value, string $pattern): int|false
    {
        $pattern = str_replace('~', '\\~', $pattern);

        return @preg_match("~{$pattern}~u", $value);
    }

    private function matchesFormat(string $value, string $format): bool
    {
        return match (strtolower($format)) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1,
            'uri', 'url' => preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:[^\s]+$/', $value) === 1,
            'ipv4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'date' => $this->isDate($value),
            'date-time' => $this->isDateTime($value),
            default => true,
        };
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isDateTime(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) !== 1) {
            return false;
        }

        try {
            new DateTimeImmutable($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function equivalent(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return (float) $left === (float) $right;
        }

        return $this->normalizedValue($left) === $this->normalizedValue($right);
    }

    private function normalizedValue(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(fn (mixed $item): mixed => $this->normalizedValue($item), $value);

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    private function resolvePointer(array $document, string $ref): mixed
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $node = $document;

        foreach (explode('/', substr($ref, 2)) as $part) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $part);

            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }
}
