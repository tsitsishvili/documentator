<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Support;

/**
 * Small, human-oriented OpenAPI diff for CI output. It is not a full semantic
 * compatibility checker, but it highlights the contract changes people need to
 * review before regenerating a committed spec.
 */
final class OpenApiDiff
{
    /** @var array<string, mixed> */
    private static array $expectedDocument = [];

    /** @var array<string, mixed> */
    private static array $actualDocument = [];

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    public static function compare(array $expected, array $actual): array
    {
        self::$expectedDocument = $expected;
        self::$actualDocument = $actual;
        $changes = self::compareSecurity('document', $expected['security'] ?? null, $actual['security'] ?? null);
        $expectedPaths = $expected['paths'] ?? [];
        $actualPaths = $actual['paths'] ?? [];

        foreach (array_diff(array_keys($expectedPaths), array_keys($actualPaths)) as $path) {
            $changes[] = self::change('breaking', $path, 'path removed');
        }

        foreach (array_diff(array_keys($actualPaths), array_keys($expectedPaths)) as $path) {
            $changes[] = self::change('non-breaking', $path, 'path added');
        }

        foreach (array_intersect(array_keys($expectedPaths), array_keys($actualPaths)) as $path) {
            $changes = array_merge($changes, self::comparePath($path, $expectedPaths[$path], $actualPaths[$path]));
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function comparePath(string $path, array $expected, array $actual): array
    {
        $changes = [];
        $verbs = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];
        $expectedOps = array_intersect(array_keys($expected), $verbs);
        $actualOps = array_intersect(array_keys($actual), $verbs);

        foreach (array_diff($expectedOps, $actualOps) as $verb) {
            $changes[] = self::change('breaking', strtoupper($verb).' '.$path, 'operation removed');
        }

        foreach (array_diff($actualOps, $expectedOps) as $verb) {
            $changes[] = self::change('non-breaking', strtoupper($verb).' '.$path, 'operation added');
        }

        foreach (array_intersect($expectedOps, $actualOps) as $verb) {
            $location = strtoupper($verb).' '.$path;
            $changes = array_merge(
                $changes,
                self::compareSecurity($location, $expected[$verb]['security'] ?? null, $actual[$verb]['security'] ?? null),
                self::compareParameters($location, $expected[$verb]['parameters'] ?? [], $actual[$verb]['parameters'] ?? []),
                self::compareRequestBody($location, $expected[$verb]['requestBody'] ?? null, $actual[$verb]['requestBody'] ?? null),
                self::compareResponses($location, $expected[$verb]['responses'] ?? [], $actual[$verb]['responses'] ?? []),
            );
        }

        return $changes;
    }

    /**
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareSecurity(string $location, mixed $expected, mixed $actual): array
    {
        if ($expected === $actual) {
            return [];
        }

        if (($expected === null || $expected === []) && $actual !== null && $actual !== []) {
            return [self::change('breaking', $location, 'security requirement added')];
        }

        if ($expected !== null && ($actual === null || $actual === [])) {
            return [self::change('non-breaking', $location, 'security requirement removed')];
        }

        $old = self::securityByScheme($expected);
        $new = self::securityByScheme($actual);
        $changes = [];

        foreach (array_diff(array_keys($new), array_keys($old)) as $scheme) {
            $changes[] = self::change('breaking', $location, "security scheme required: {$scheme}");
        }

        foreach (array_diff(array_keys($old), array_keys($new)) as $scheme) {
            $changes[] = self::change('non-breaking', $location, "security scheme no longer required: {$scheme}");
        }

        foreach (array_intersect(array_keys($old), array_keys($new)) as $scheme) {
            if (array_diff($new[$scheme], $old[$scheme]) !== []) {
                $changes[] = self::change('breaking', $location, "security scope added for {$scheme}");
            }

            if (array_diff($old[$scheme], $new[$scheme]) !== []) {
                $changes[] = self::change('non-breaking', $location, "security scope removed for {$scheme}");
            }
        }

        return $changes !== [] ? $changes : [self::change('changed', $location, 'security requirement changed')];
    }

    /** @return array<string, array<int, string>> */
    private static function securityByScheme(mixed $requirements): array
    {
        $schemes = [];

        foreach (is_array($requirements) ? $requirements : [] as $requirement) {
            foreach (is_array($requirement) ? $requirement : [] as $scheme => $scopes) {
                $schemes[(string) $scheme] = array_values(array_unique(array_map('strval', is_array($scopes) ? $scopes : [])));
                sort($schemes[(string) $scheme]);
            }
        }

        return $schemes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $expected
     * @param  array<int, array<string, mixed>>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareParameters(string $location, array $expected, array $actual): array
    {
        $changes = [];
        $expectedByKey = self::parametersByKey($expected);
        $actualByKey = self::parametersByKey($actual);

        foreach (array_diff(array_keys($expectedByKey), array_keys($actualByKey)) as $key) {
            $changes[] = self::change('breaking', $location, "parameter removed: {$key}");
        }

        foreach (array_diff(array_keys($actualByKey), array_keys($expectedByKey)) as $key) {
            $severity = ($actualByKey[$key]['required'] ?? false) ? 'breaking' : 'non-breaking';
            $changes[] = self::change($severity, $location, "parameter added: {$key}");
        }

        foreach (array_intersect(array_keys($expectedByKey), array_keys($actualByKey)) as $key) {
            $oldRequired = (bool) ($expectedByKey[$key]['required'] ?? false);
            $newRequired = (bool) ($actualByKey[$key]['required'] ?? false);

            if (! $oldRequired && $newRequired) {
                $changes[] = self::change('breaking', $location, "parameter became required: {$key}");
            }

            $oldSchema = $expectedByKey[$key]['schema'] ?? null;
            $newSchema = $actualByKey[$key]['schema'] ?? null;

            if (is_array($oldSchema) && is_array($newSchema)) {
                $changes = array_merge($changes, self::compareSchema($location." parameter {$key}", $oldSchema, $newSchema));
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $parameters
     * @return array<string, array<string, mixed>>
     */
    private static function parametersByKey(array $parameters): array
    {
        $byKey = [];

        foreach ($parameters as $parameter) {
            $byKey[($parameter['in'] ?? 'query').':'.($parameter['name'] ?? '')] = $parameter;
        }

        return $byKey;
    }

    /**
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareRequestBody(string $location, mixed $expected, mixed $actual): array
    {
        if (! is_array($expected)) {
            if (! is_array($actual)) {
                return [];
            }

            $severity = ($actual['required'] ?? false) ? 'breaking' : 'non-breaking';

            return [self::change($severity, $location, 'request body added')];
        }

        if (! is_array($actual)) {
            return [self::change('breaking', $location, 'request body removed')];
        }

        $changes = [];
        $oldRequired = (bool) ($expected['required'] ?? false);
        $newRequired = (bool) ($actual['required'] ?? false);

        if (! $oldRequired && $newRequired) {
            $changes[] = self::change('breaking', $location, 'request body became required');
        }

        return array_merge(
            $changes,
            self::compareContentSchemas($location.' request body', $expected['content'] ?? [], $actual['content'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareResponses(string $location, array $expected, array $actual): array
    {
        $changes = [];

        foreach (array_diff(array_keys($expected), array_keys($actual)) as $status) {
            $changes[] = self::change('breaking', $location, "response removed: {$status}");
        }

        foreach (array_diff(array_keys($actual), array_keys($expected)) as $status) {
            $changes[] = self::change('non-breaking', $location, "response added: {$status}");
        }

        foreach (array_intersect(array_keys($expected), array_keys($actual)) as $status) {
            $changes = array_merge(
                $changes,
                self::compareContentSchemas($location." response {$status}", $expected[$status]['content'] ?? [], $actual[$status]['content'] ?? []),
                self::compareResponseHeaders($location." response {$status}", $expected[$status]['headers'] ?? [], $actual[$status]['headers'] ?? []),
            );
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareResponseHeaders(string $location, array $expected, array $actual): array
    {
        $changes = [];
        $old = array_change_key_case($expected, CASE_LOWER);
        $new = array_change_key_case($actual, CASE_LOWER);

        foreach (array_diff(array_keys($old), array_keys($new)) as $header) {
            $changes[] = self::change('breaking', $location, "response header removed: {$header}");
        }

        foreach (array_diff(array_keys($new), array_keys($old)) as $header) {
            $changes[] = self::change('non-breaking', $location, "response header added: {$header}");
        }

        foreach (array_intersect(array_keys($old), array_keys($new)) as $header) {
            if (is_array($old[$header]['schema'] ?? null) && is_array($new[$header]['schema'] ?? null)) {
                $changes = array_merge($changes, self::compareSchema("{$location} header {$header}", $old[$header]['schema'], $new[$header]['schema']));
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareContentSchemas(string $location, array $expected, array $actual): array
    {
        $changes = [];

        foreach (array_diff(array_keys($expected), array_keys($actual)) as $mediaType) {
            $changes[] = self::change('breaking', $location, "content type removed: {$mediaType}");
        }

        foreach (array_diff(array_keys($actual), array_keys($expected)) as $mediaType) {
            $changes[] = self::change('non-breaking', $location, "content type added: {$mediaType}");
        }

        foreach (array_intersect(array_keys($expected), array_keys($actual)) as $mediaType) {
            $oldSchema = $expected[$mediaType]['schema'] ?? null;
            $newSchema = $actual[$mediaType]['schema'] ?? null;

            if (is_array($oldSchema) && is_array($newSchema)) {
                $changes = array_merge($changes, self::compareSchema("{$location} {$mediaType}", $oldSchema, $newSchema));
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareSchema(string $location, array $expected, array $actual, int $depth = 0): array
    {
        if ($depth > 20) {
            return [];
        }

        $expected = self::resolveSchema($expected, self::$expectedDocument);
        $actual = self::resolveSchema($actual, self::$actualDocument);
        $changes = [];

        if (isset($expected['$ref']) && isset($actual['$ref']) && $expected['$ref'] !== $actual['$ref']) {
            return [self::change('changed', $location, 'schema reference changed')];
        }

        $oldType = self::schemaTypes($expected);
        $newType = self::schemaTypes($actual);

        if ($oldType !== [] && $newType !== [] && $oldType !== $newType) {
            $removedTypes = array_diff($oldType, $newType);
            $addedTypes = array_diff($newType, $oldType);

            if ($removedTypes !== [] && $addedTypes !== []) {
                return [self::change('breaking', $location, 'schema type changed ('.implode('|', $oldType).' -> '.implode('|', $newType).')')];
            }

            if ($removedTypes !== []) {
                $message = in_array('null', $removedTypes, true)
                    ? 'schema no longer nullable'
                    : 'schema type removed: '.implode('|', $removedTypes);
                $changes[] = self::change('breaking', $location, $message);
            }

            if ($addedTypes !== []) {
                $message = in_array('null', $addedTypes, true)
                    ? 'schema became nullable'
                    : 'schema type added: '.implode('|', $addedTypes);
                $changes[] = self::change('non-breaking', $location, $message);
            }
        }

        if (($expected['format'] ?? null) !== ($actual['format'] ?? null)) {
            $changes[] = self::change(
                isset($actual['format']) ? 'breaking' : 'non-breaking',
                $location,
                'schema format changed',
            );
        }

        $changes = array_merge($changes, self::compareEnums($location, $expected['enum'] ?? null, $actual['enum'] ?? null));
        $changes = array_merge(
            $changes,
            self::compareExactConstraint($location, 'pattern', $expected, $actual),
            self::compareExactConstraint($location, 'const', $expected, $actual),
            self::compareExactConstraint($location, 'multipleOf', $expected, $actual),
        );

        foreach (['minimum', 'exclusiveMinimum', 'minLength', 'minItems', 'minProperties'] as $constraint) {
            $changes = array_merge($changes, self::compareBoundary($location, $constraint, $expected, $actual, true));
        }

        foreach (['maximum', 'exclusiveMaximum', 'maxLength', 'maxItems', 'maxProperties'] as $constraint) {
            $changes = array_merge($changes, self::compareBoundary($location, $constraint, $expected, $actual, false));
        }

        $changes = array_merge($changes, self::compareAdditionalProperties($location, $expected, $actual, $depth));

        foreach (['oneOf', 'anyOf', 'allOf'] as $composite) {
            $changes = array_merge($changes, self::compareComposite($location, $composite, $expected, $actual));
        }

        $oldRequired = is_array($expected['required'] ?? null) ? $expected['required'] : [];
        $newRequired = is_array($actual['required'] ?? null) ? $actual['required'] : [];

        foreach (array_diff($newRequired, $oldRequired) as $property) {
            $changes[] = self::change('breaking', $location, "property became required: {$property}");
        }

        foreach (array_diff($oldRequired, $newRequired) as $property) {
            $changes[] = self::change('non-breaking', $location, "property became optional: {$property}");
        }

        if (is_array($expected['items'] ?? null) && is_array($actual['items'] ?? null)) {
            $changes = array_merge($changes, self::compareSchema($location.'[]', $expected['items'], $actual['items'], $depth + 1));
        } elseif (is_array($actual['items'] ?? null)) {
            $changes[] = self::change('breaking', $location, 'array item schema added');
        } elseif (is_array($expected['items'] ?? null)) {
            $changes[] = self::change('non-breaking', $location, 'array item schema removed');
        }

        $oldProps = $expected['properties'] ?? [];
        $newProps = $actual['properties'] ?? [];

        if (! is_array($oldProps) || ! is_array($newProps)) {
            return $changes;
        }

        foreach (array_diff(array_keys($oldProps), array_keys($newProps)) as $property) {
            $changes[] = self::change('breaking', $location, "property removed: {$property}");
        }

        foreach (array_diff(array_keys($newProps), array_keys($oldProps)) as $property) {
            $changes[] = self::change('non-breaking', $location, "property added: {$property}");
        }

        foreach (array_intersect(array_keys($oldProps), array_keys($newProps)) as $property) {
            if (is_array($oldProps[$property]) && is_array($newProps[$property])) {
                $changes = array_merge($changes, self::compareSchema($location.'.'.$property, $oldProps[$property], $newProps[$property], $depth + 1));
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareExactConstraint(string $location, string $key, array $expected, array $actual): array
    {
        $oldExists = array_key_exists($key, $expected);
        $newExists = array_key_exists($key, $actual);

        if (! $oldExists && ! $newExists) {
            return [];
        }

        if ($oldExists && ! $newExists) {
            return [self::change('non-breaking', $location, "{$key} constraint removed")];
        }

        if (! $oldExists) {
            return [self::change('breaking', $location, "{$key} constraint added")];
        }

        return $expected[$key] === $actual[$key]
            ? []
            : [self::change('breaking', $location, "{$key} constraint changed")];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareBoundary(string $location, string $key, array $expected, array $actual, bool $higherIsNarrower): array
    {
        $old = $expected[$key] ?? null;
        $new = $actual[$key] ?? null;

        if ($old === $new) {
            return [];
        }

        if (! is_numeric($old)) {
            return is_numeric($new) ? [self::change('breaking', $location, "{$key} constraint added")] : [];
        }

        if (! is_numeric($new)) {
            return [self::change('non-breaking', $location, "{$key} constraint removed")];
        }

        $narrower = $higherIsNarrower ? $new > $old : $new < $old;

        return [self::change($narrower ? 'breaking' : 'non-breaking', $location, "{$key} constraint changed ({$old} -> {$new})")];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareAdditionalProperties(string $location, array $expected, array $actual, int $depth): array
    {
        $old = $expected['additionalProperties'] ?? true;
        $new = $actual['additionalProperties'] ?? true;

        if ($old === $new) {
            return [];
        }

        if ($new === false) {
            return [self::change('breaking', $location, 'additional properties are no longer allowed')];
        }

        if ($old === false) {
            return [self::change('non-breaking', $location, 'additional properties are now allowed')];
        }

        if (is_array($old) && is_array($new)) {
            return self::compareSchema($location.'.*', $old, $new, $depth + 1);
        }

        return is_array($new)
            ? [self::change('breaking', $location, 'additional properties schema added')]
            : [self::change('non-breaking', $location, 'additional properties schema removed')];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareComposite(string $location, string $key, array $expected, array $actual): array
    {
        $old = self::schemaSet($expected[$key] ?? []);
        $new = self::schemaSet($actual[$key] ?? []);

        if ($old === $new) {
            return [];
        }

        $changes = [];

        if (array_diff($old, $new) !== []) {
            $severity = $key === 'allOf' ? 'non-breaking' : 'breaking';
            $changes[] = self::change($severity, $location, "{$key} schema removed");
        }

        if (array_diff($new, $old) !== []) {
            $severity = $key === 'allOf' ? 'breaking' : 'non-breaking';
            $changes[] = self::change($severity, $location, "{$key} schema added");
        }

        return $changes;
    }

    /** @return array<int, string> */
    private static function schemaSet(mixed $schemas): array
    {
        $set = [];

        foreach (is_array($schemas) ? $schemas : [] as $schema) {
            if (! is_array($schema)) {
                continue;
            }

            $set[] = json_encode(self::sortRecursive($schema), JSON_UNESCAPED_SLASHES) ?: '';
        }

        sort($set);

        return $set;
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as &$item) {
            $item = self::sortRecursive($item);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function resolveSchema(array $schema, array $document): array
    {
        $ref = $schema['$ref'] ?? null;

        if (! is_string($ref) || ! str_starts_with($ref, '#/')) {
            return $schema;
        }

        $resolved = self::resolvePointer($document, $ref);

        if (! is_array($resolved)) {
            return $schema;
        }

        unset($schema['$ref']);

        return array_replace($resolved, $schema);
    }

    /** @param array<string, mixed> $document */
    private static function resolvePointer(array $document, string $ref): mixed
    {
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

    /**
     * @return array<int, string>
     */
    private static function schemaTypes(array $schema): array
    {
        $type = $schema['type'] ?? null;
        $types = is_array($type) ? $type : ($type === null ? [] : [$type]);
        sort($types);

        return $types;
    }

    /**
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    private static function compareEnums(string $location, mixed $expected, mixed $actual): array
    {
        if (! is_array($expected)) {
            return is_array($actual)
                ? [self::change('breaking', $location, 'enum constraint added')]
                : [];
        }

        if (! is_array($actual)) {
            return [self::change('non-breaking', $location, 'enum constraint removed')];
        }

        $changes = [];
        $removed = array_udiff($expected, $actual, fn ($a, $b) => strcmp(json_encode($a) ?: '', json_encode($b) ?: ''));
        $added = array_udiff($actual, $expected, fn ($a, $b) => strcmp(json_encode($a) ?: '', json_encode($b) ?: ''));

        if ($removed !== []) {
            $changes[] = self::change('breaking', $location, 'enum value removed');
        }

        if ($added !== []) {
            $changes[] = self::change('non-breaking', $location, 'enum value added');
        }

        return $changes;
    }

    /**
     * @return array{severity: string, location: string, message: string}
     */
    private static function change(string $severity, string $location, string $message): array
    {
        return compact('severity', 'location', 'message');
    }
}
