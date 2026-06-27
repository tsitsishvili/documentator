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
    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array<int, array{severity: string, location: string, message: string}>
     */
    public static function compare(array $expected, array $actual): array
    {
        $changes = [];
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

        return [self::change('changed', $location, 'security requirement changed')];
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
            );
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
    private static function compareSchema(string $location, array $expected, array $actual): array
    {
        $changes = [];

        if (($expected['$ref'] ?? null) !== ($actual['$ref'] ?? null)) {
            return [self::change('changed', $location, 'schema reference changed')];
        }

        $oldType = self::schemaTypes($expected);
        $newType = self::schemaTypes($actual);

        if ($oldType !== [] && $newType !== [] && $oldType !== $newType) {
            return [self::change('breaking', $location, 'schema type changed ('.implode('|', $oldType).' -> '.implode('|', $newType).')')];
        }

        if (($expected['format'] ?? null) !== ($actual['format'] ?? null)) {
            return [self::change('breaking', $location, 'schema format changed')];
        }

        $changes = array_merge($changes, self::compareEnums($location, $expected['enum'] ?? null, $actual['enum'] ?? null));

        $oldRequired = is_array($expected['required'] ?? null) ? $expected['required'] : [];
        $newRequired = is_array($actual['required'] ?? null) ? $actual['required'] : [];

        foreach (array_diff($newRequired, $oldRequired) as $property) {
            $changes[] = self::change('breaking', $location, "property became required: {$property}");
        }

        foreach (array_diff($oldRequired, $newRequired) as $property) {
            $changes[] = self::change('non-breaking', $location, "property became optional: {$property}");
        }

        if (is_array($expected['items'] ?? null) && is_array($actual['items'] ?? null)) {
            $changes = array_merge($changes, self::compareSchema($location.'[]', $expected['items'], $actual['items']));
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
                $changes = array_merge($changes, self::compareSchema($location.'.'.$property, $oldProps[$property], $newProps[$property]));
            }
        }

        return $changes;
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
