<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

/**
 * Parses a practical subset of PHPDoc/PHPStan-style type strings into OpenAPI
 * schemas for manual attributes: scalars, nullable unions, lists, T[], and
 * array shapes such as `array{id: int, email?: string}`.
 */
final class TypeStringParser
{
    /**
     * @return array<string, mixed>|null
     */
    public static function parse(?string $type): ?array
    {
        $type = trim((string) $type);

        if ($type === '') {
            return null;
        }

        return self::parseType($type);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseType(string $type): ?array
    {
        $type = trim($type);

        if ($type === '') {
            return null;
        }

        if (str_starts_with($type, '?')) {
            return self::nullable(self::parseType(substr($type, 1)) ?? ['type' => 'string']);
        }

        $parts = self::splitTopLevel($type, '|');

        if (count($parts) > 1) {
            $nullable = false;
            $schemas = [];

            foreach ($parts as $part) {
                if (in_array(strtolower($part), ['null', 'nil'], true)) {
                    $nullable = true;

                    continue;
                }

                $schema = self::parseType($part);

                if ($schema !== null) {
                    $schemas[] = $schema;
                }
            }

            if (count($schemas) === 1) {
                return $nullable ? self::nullable($schemas[0]) : $schemas[0];
            }

            $schema = ['oneOf' => $schemas === [] ? [['type' => 'string']] : $schemas];

            return $nullable ? self::nullable($schema) : $schema;
        }

        if (preg_match('/^(.+)\[\]$/', $type, $matches) === 1) {
            return ['type' => 'array', 'items' => self::parseType($matches[1]) ?? ['type' => 'string']];
        }

        if (preg_match('/^(?:list|array)<(.+)>$/i', $type, $matches) === 1) {
            $inner = self::splitTopLevel($matches[1], ',');
            $itemType = count($inner) === 2 ? $inner[1] : $inner[0];

            return ['type' => 'array', 'items' => self::parseType($itemType) ?? ['type' => 'string']];
        }

        if (preg_match('/^(?:array|object)\{(.+)\}$/i', $type, $matches) === 1) {
            return self::shape($matches[1]);
        }

        return self::named($type);
    }

    /**
     * @return array<string, mixed>
     */
    private static function shape(string $body): array
    {
        $properties = [];
        $required = [];

        foreach (self::splitTopLevel($body, ',') as $field) {
            $parts = self::splitTopLevel($field, ':');

            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0], " \t\n\r\0\x0B'\"");
            $optional = str_ends_with($name, '?');
            $name = rtrim($name, '?');

            if ($name === '') {
                continue;
            }

            $properties[$name] = self::parseType($parts[1]) ?? ['type' => 'string'];

            if (! $optional) {
                $required[] = $name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function named(string $type): array
    {
        $type = strtolower(trim($type, '\\'));

        return match ($type) {
            'int', 'integer', 'positive-int', 'negative-int' => ['type' => 'integer'],
            'float', 'double', 'real', 'numeric' => ['type' => 'number'],
            'bool', 'boolean', 'true', 'false' => ['type' => 'boolean'],
            'array', 'iterable' => ['type' => 'array', 'items' => ['type' => 'string']],
            'object', 'stdclass' => ['type' => 'object'],
            'mixed', 'scalar' => ['oneOf' => [
                ['type' => 'string'],
                ['type' => 'number'],
                ['type' => 'boolean'],
                ['type' => 'object'],
                ['type' => 'array'],
            ]],
            'uuid' => ['type' => 'string', 'format' => 'uuid'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url', 'uri' => ['type' => 'string', 'format' => 'uri'],
            'date', 'date-time', 'datetime', 'datetimeinterface', 'carbon\\carbon' => ['type' => 'string', 'format' => 'date-time'],
            default => ['type' => 'string'],
        };
    }

    /**
     * @return array<int, string>
     */
    private static function splitTopLevel(string $value, string $separator): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if (in_array($char, ['{', '<', '('], true)) {
                $depth++;
            } elseif (in_array($char, ['}', '>', ')'], true)) {
                $depth = max(0, $depth - 1);
            }

            if ($char === $separator && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function nullable(array $schema): array
    {
        $schema['nullable'] = true;

        return $schema;
    }
}
