<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Support;

use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rules\Date;
use Illuminate\Validation\Rules\Numeric;
use Throwable;
use Tsitsishvili\Documentator\Contracts\ValidationRuleTransformer;
use Tsitsishvili\Documentator\Data\ParameterData;

/**
 * Translates a Laravel validation `rules()` array into documented parameters
 * with full OpenAPI schemas: types, formats, enums, bounds, nullability, and
 * nested structures inferred from dotted / wildcard keys (e.g. `items.*.id`).
 */
final class RuleParser
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<int, ParameterData>
     */
    public static function parse(array $rules): array
    {
        $root = ['type' => 'object', 'properties' => [], 'required' => []];
        $confirmed = [];

        foreach ($rules as $field => $ruleset) {
            if (! is_string($field)) {
                continue;
            }
            $list = self::ruleList($ruleset);
            $segments = self::fieldSegments($field);
            self::insert($root, $segments, $list, $field);

            // `confirmed` makes Laravel expect a matching `{field}_confirmation`.
            if (count($segments) === 1 && self::has($list, 'confirmed')) {
                $confirmed[] = $segments[0];
            }
        }

        $params = [];
        foreach ($root['properties'] as $name => $schema) {
            $params[$name] = new ParameterData(
                name: $name,
                type: $schema['type'] ?? 'string',
                required: in_array($name, $root['required'], true),
                schema: $schema,
            );
        }

        foreach ($confirmed as $field) {
            $name = $field.'_confirmation';
            if (! isset($params[$field]) || isset($params[$name])) {
                continue;
            }
            $base = $params[$field];
            $params[$name] = new ParameterData(
                name: $name,
                type: $base->type,
                required: $base->required,
                description: "Confirmation of the `{$field}` field; must match.",
                schema: $base->schema,
            );
        }

        return array_values($params);
    }

    /**
     * Whether any field maps to a binary upload (so the body must be multipart).
     *
     * @param  array<int, ParameterData>  $params
     */
    public static function hasUpload(array $params): bool
    {
        foreach ($params as $param) {
            if ($param->schema !== null && self::schemaHasBinary($param->schema)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function schemaHasBinary(array $schema): bool
    {
        if (($schema['format'] ?? null) === 'binary') {
            return true;
        }
        if (isset($schema['items']) && self::schemaHasBinary($schema['items'])) {
            return true;
        }
        foreach ($schema['properties'] ?? [] as $child) {
            if (self::schemaHasBinary($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $segments
     * @param  array<int, string>  $rules
     * @param  array<string, mixed>  $node
     */
    private static function insert(array &$node, array $segments, array $rules, string $field): void
    {
        $seg = array_shift($segments);

        if ($seg === '*') {
            $node['type'] = 'array';
            $node['items'] ??= [];
            if ($segments === []) {
                $node['items'] = self::scalar($rules, $field);
            } else {
                self::insert($node['items'], $segments, $rules, $field);
            }

            return;
        }

        $node['type'] = 'object';
        $node['properties'] ??= [];
        $node['properties'][$seg] ??= [];

        if ($segments === []) {
            $node['properties'][$seg] = self::mergeScalar($node['properties'][$seg], $rules, $field);
            if (self::has($rules, 'required')) {
                $node['required'] ??= [];
                if (! in_array($seg, $node['required'], true)) {
                    $node['required'][] = $seg;
                }
            }
        } else {
            self::insert($node['properties'][$seg], $segments, $rules, $field);
        }
    }

    /**
     * Keep a container schema (built from deeper rules) if one already exists,
     * otherwise produce a scalar schema from the rules.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<int, string>  $rules
     * @return array<string, mixed>
     */
    private static function mergeScalar(array $existing, array $rules, string $field): array
    {
        if (isset($existing['properties']) || isset($existing['items'])) {
            if (self::has($rules, 'nullable')) {
                $existing['nullable'] = true;
            }

            return $existing;
        }

        return self::scalar($rules, $field);
    }

    /**
     * @param  array<int, string>  $rules
     * @return array<string, mixed>
     */
    private static function scalar(array $rules, string $field): array
    {
        $type = self::typeFor($rules);
        $enum = self::enumFor($rules);

        // An all-numeric enum (e.g. an int-backed PHP enum via Rule::enum) is an
        // integer, not a string of digits.
        if ($enum !== null && $type === 'string' && self::allNumeric($enum)) {
            $type = 'integer';
            $enum = array_map(static fn (string $value) => (int) $value, $enum);
        }

        $schema = ['type' => $type];

        if ($format = self::formatFor($rules)) {
            $schema['format'] = $format;
        }
        if ($enum !== null) {
            $schema['enum'] = $enum;
        }
        if ($pattern = self::pattern($rules)) {
            $schema['pattern'] = $pattern;
        }

        [$min, $max] = self::bounds($rules);
        if ($type === 'integer' || $type === 'number') {
            if ($min !== null) {
                $schema['minimum'] = $min + 0;
            }
            if ($max !== null) {
                $schema['maximum'] = $max + 0;
            }
        } elseif ($type === 'string' && $format !== 'binary') {
            // A file's min/max are kilobytes, not a string length.
            if ($min !== null) {
                $schema['minLength'] = (int) $min;
            }
            if ($max !== null) {
                $schema['maxLength'] = (int) $max;
            }
        } elseif ($type === 'array') {
            if ($min !== null) {
                $schema['minItems'] = (int) $min;
            }
            if ($max !== null) {
                $schema['maxItems'] = (int) $max;
            }
        }

        if (self::has($rules, 'nullable')) {
            $schema['nullable'] = true;
        }

        return self::applyTransformers($schema, $rules, $field);
    }

    /**
     * Normalise a rule definition into a list of raw rule strings, splitting on
     * `|` exactly where Laravel does: a string ruleset and the fluent Date/Numeric
     * rule objects. Strings inside an array are kept whole (so `regex:/a|b/`
     * survives). Other rule objects (e.g. `Rule::in()`) are kept by casting to
     * their string form (`in:"a","b"`); closures and anything else are dropped.
     *
     * @return array<int, string>
     */
    private static function ruleList(mixed $ruleset): array
    {
        $raw = is_string($ruleset) ? explode('|', $ruleset) : (is_array($ruleset) ? $ruleset : [$ruleset]);

        $rules = [];
        foreach ($raw as $rule) {
            if (is_string($rule)) {
                $rules[] = $rule;
            } elseif ($rule instanceof Date || $rule instanceof Numeric) {
                array_push($rules, ...explode('|', (string) $rule));
            } elseif ($rule instanceof ConditionalRules) {
                array_push($rules, ...self::ruleList($rule->rules()));
                array_push($rules, ...self::ruleList($rule->defaultRules()));
            } elseif (is_object($rule) && method_exists($rule, '__toString')) {
                $rules[] = (string) $rule;
            }
        }

        return array_values(array_filter($rules, fn (string $rule): bool => $rule !== ''));
    }

    /**
     * @param  array<int, string>  $rules
     */
    private static function has(array $rules, string $name): bool
    {
        foreach ($rules as $rule) {
            if (strtolower(explode(':', $rule, 2)[0]) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $rules
     */
    private static function arg(array $rules, string $name): ?string
    {
        foreach ($rules as $rule) {
            $parts = explode(':', $rule, 2);
            if (strtolower($parts[0]) === $name && isset($parts[1])) {
                return $parts[1];
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $rules
     */
    private static function typeFor(array $rules): string
    {
        return match (true) {
            self::has($rules, 'integer'), self::has($rules, 'digits'), self::has($rules, 'digits_between') => 'integer',
            self::has($rules, 'numeric'), self::has($rules, 'decimal') => 'number',
            self::has($rules, 'boolean'), self::has($rules, 'bool'), self::has($rules, 'accepted'), self::has($rules, 'declined') => 'boolean',
            self::has($rules, 'array'), self::has($rules, 'list') => 'array',
            self::has($rules, 'string'), self::has($rules, 'uuid'), self::has($rules, 'ulid') => 'string',
            // No explicit type: an id-shaped exists: column is most likely an integer key.
            self::existsOnIdColumn($rules) => 'integer',
            default => 'string',
        };
    }

    /**
     * @param  array<int, string>  $rules
     */
    private static function existsOnIdColumn(array $rules): bool
    {
        if (($exists = self::arg($rules, 'exists')) === null) {
            return false;
        }

        $column = strtolower(explode(',', $exists)[1] ?? '');

        return $column === 'id' || $column === '' || str_ends_with($column, '_id');
    }

    /**
     * The ECMA pattern for a `regex:` rule, with the PHP delimiters/flags peeled
     * off so it's a valid JSON Schema `pattern`.
     *
     * @param  array<int, string>  $rules
     */
    private static function pattern(array $rules): ?string
    {
        $regex = self::arg($rules, 'regex');

        if ($regex === null) {
            return null;
        }

        return preg_match('#^/(.*)/[a-zA-Z]*$#s', $regex, $matches) === 1 ? $matches[1] : $regex;
    }

    /**
     * @param  array<int, string|int>  $values
     */
    private static function allNumeric(array $values): bool
    {
        if ($values === []) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_numeric($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $rules
     */
    private static function formatFor(array $rules): ?string
    {
        if (($exists = self::arg($rules, 'exists')) !== null) {
            $column = strtolower(explode(',', $exists)[1] ?? '');

            if ($column === 'uuid' || str_ends_with($column, '_uuid')) {
                return 'uuid';
            }
        }

        return match (true) {
            self::has($rules, 'file'), self::has($rules, 'image') => 'binary',
            self::has($rules, 'email') => 'email',
            self::has($rules, 'uuid') => 'uuid',
            self::has($rules, 'url') => 'uri',
            self::has($rules, 'ip'), self::has($rules, 'ipv4') => 'ipv4',
            self::has($rules, 'ipv6') => 'ipv6',
            self::has($rules, 'date') => 'date-time',
            default => self::dateFormat(self::arg($rules, 'date_format')),
        };
    }

    /**
     * `date_format:Y-m-d` is a date; a format with both a year and an hour is a
     * date-time. Anything else (e.g. `H:i`) has no JSON Schema format.
     */
    private static function dateFormat(?string $format): ?string
    {
        return match (true) {
            $format === null => null,
            $format === 'Y-m-d' => 'date',
            str_contains($format, 'Y') && str_contains($format, 'H') => 'date-time',
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $rules
     * @return array<int, string>|null
     */
    private static function enumFor(array $rules): ?array
    {
        $in = self::arg($rules, 'in');
        if ($in === null) {
            return null;
        }

        // Rule::in() quotes its values, so a value may itself contain a comma.
        return array_values(array_map(
            fn (?string $value) => trim((string) $value, "\"'"),
            array_filter(str_getcsv($in, ',', '"', ''), fn ($v) => $v !== null && $v !== ''),
        ));
    }

    /**
     * @param  array<int, string>  $rules
     * @return array{0: ?float, 1: ?float}
     */
    private static function bounds(array $rules): array
    {
        $min = self::arg($rules, 'min');
        $max = self::arg($rules, 'max');

        if (($between = self::arg($rules, 'between')) !== null) {
            $pair = explode(',', $between);
            $min ??= $pair[0] ?? null;
            $max ??= $pair[1] ?? null;
        }
        if (($size = self::arg($rules, 'size')) !== null) {
            $min ??= $size;
            $max ??= $size;
        }

        return [
            is_numeric($min) ? (float) $min : null,
            is_numeric($max) ? (float) $max : null,
        ];
    }

    /**
     * Split Laravel validation field keys on unescaped dots. `user\.name`
     * addresses a literal key named "user.name", while `user.name` nests.
     *
     * @return array<int, string>
     */
    private static function fieldSegments(string $field): array
    {
        $segments = [];
        $current = '';
        $escaped = false;
        $length = strlen($field);

        for ($i = 0; $i < $length; $i++) {
            $char = $field[$i];

            if ($escaped) {
                $current .= $char;
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === '.') {
                $segments[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if ($escaped) {
            $current .= '\\';
        }

        $segments[] = $current;

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $rules
     * @return array<string, mixed>
     */
    private static function applyTransformers(array $schema, array $rules, string $field): array
    {
        foreach (self::transformers() as $transformer) {
            foreach ($rules as $rule) {
                try {
                    $transformed = $transformer->transform($rule, $schema, $rules, $field);
                } catch (Throwable) {
                    continue;
                }

                if (is_array($transformed)) {
                    $schema = $transformed;
                }
            }
        }

        return $schema;
    }

    /**
     * @return array<int, ValidationRuleTransformer>
     */
    private static function transformers(): array
    {
        $transformers = [];

        foreach ((array) config('documentator.extensions.validation_rule_transformers', []) as $transformer) {
            try {
                $instance = is_string($transformer) && function_exists('app')
                    ? app($transformer)
                    : $transformer;
            } catch (Throwable) {
                continue;
            }

            if ($instance instanceof ValidationRuleTransformer) {
                $transformers[] = $instance;
            }
        }

        return $transformers;
    }
}
