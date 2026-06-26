<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Data\ParameterData;
use Tsitsishvili\Documentator\Extraction\ExtractionStrategy;

/**
 * Seeds the endpoint from the route itself: verbs, URI, name, controller, path
 * parameters, an auth guess from middleware, and a humanised summary.
 */
final class ExtractRouteMetadata implements ExtractionStrategy
{
    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData
    {
        $endpoint->httpMethods = $route->methods();
        $endpoint->uri = $route->uri();
        $endpoint->routeName = $route->getName();

        if ($method !== null) {
            $endpoint->controller = $method->class;
            $endpoint->method = $method->name;
            $endpoint->summary = Str::headline($method->name);
            $this->applyDocblock($endpoint, $method);
        }

        foreach ($this->pathParameters($route->uri()) as $name => $required) {
            $type = $this->pathParameterType($name, $route, $method);
            $endpoint->pathParameters[$name] = new ParameterData(
                name: $name,
                type: $type,
                required: $required,
                schema: ['type' => $type],
            );
        }

        if (($scheme = $this->authScheme($route)) !== null) {
            $endpoint->authenticated = true;
            $endpoint->securityScheme = $scheme;
            $endpoint->securityScopes = $this->securityScopes($route);
        }

        return $endpoint;
    }

    /**
     * Pull a human summary + markdown description from the controller method's
     * PHPDoc: the first paragraph becomes the summary, the rest the description.
     * `@tag` lines and the comment markers are stripped. Either may be overridden
     * later by #[Summary] / #[Description]. A docblock that is only annotations
     * (no prose) leaves the headline summary untouched.
     */
    private function applyDocblock(EndpointData $endpoint, ReflectionMethod $method): void
    {
        $doc = $method->getDocComment();

        if ($doc === false) {
            return;
        }

        $lines = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim($line);
            $line = (string) preg_replace('#^/\*\*?#', '', $line); // opening /** or /*
            $line = (string) preg_replace('#\*/$#', '', $line);    // closing */
            $line = (string) preg_replace('#^\*\s?#', '', $line);  // leading * per line

            // Prose stops at the first annotation line (@param, @return, …).
            if (preg_match('/^@\w+/', trim($line))) {
                break;
            }

            $lines[] = rtrim($line);
        }

        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        if ($lines === []) {
            return;
        }

        $summary = [];
        $index = 0;
        for ($count = count($lines); $index < $count && trim($lines[$index]) !== ''; $index++) {
            $summary[] = trim($lines[$index]);
        }

        if ($summary !== []) {
            $endpoint->summary = implode(' ', $summary);
        }

        $description = trim(implode("\n", array_slice($lines, $index)));

        if ($description !== '') {
            $endpoint->description = $description;
        }
    }

    /**
     * @return array<string, bool> parameter name => required
     */
    private function pathParameters(string $uri): array
    {
        preg_match_all('/\{(\w+?)(\?)?\}/', $uri, $matches, PREG_SET_ORDER);

        $params = [];

        foreach ($matches as $match) {
            $params[$match[1]] = ! isset($match[2]);
        }

        return $params;
    }

    /**
     * Best-effort type for a path parameter, in order of confidence: a numeric
     * route constraint (`->whereNumber()` / a digits-only regex), the key type of
     * an implicitly bound model, then an id-shaped name. Defaults to `string`
     * (slugs, uuids, free identifiers).
     */
    private function pathParameterType(string $name, Route $route, ?ReflectionMethod $method): string
    {
        $pattern = $route->wheres[$name] ?? null;

        if (is_string($pattern) && in_array(trim($pattern, '^$'), ['[0-9]+', '\d+', '[1-9][0-9]*'], true)) {
            return 'integer';
        }

        if ($method !== null && ($bound = $this->boundModelKeyType($name, $route, $method)) !== null) {
            return $bound;
        }

        $lower = strtolower($name);

        return $lower === 'id' || str_ends_with($lower, '_id') ? 'integer' : 'string';
    }

    /**
     * The OpenAPI type of the field a route parameter is bound to via implicit
     * model binding: the model's key type when it's bound by primary key, or
     * `string` for a custom field (`{post:slug}`, a uuid route key). Null when the
     * parameter isn't a model binding so the caller can fall back to its heuristic.
     */
    private function boundModelKeyType(string $name, Route $route, ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() !== $name) {
                continue;
            }

            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! is_subclass_of($type->getName(), Model::class)) {
                return null;
            }

            try {
                $model = new ($type->getName());
                $field = $route->bindingFieldFor($name) ?? $model->getRouteKeyName();

                // A custom binding field (slug/uuid) is documented as a string;
                // only a primary-key binding inherits the model's key type.
                return $field === $model->getKeyName() && $model->getKeyType() === 'int'
                    ? 'integer'
                    : 'string';
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function authScheme(Route $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! Str::startsWith($middleware, ['auth', 'auth:'])) {
                continue;
            }

            $guard = Str::after($middleware, 'auth:');

            if ($guard !== $middleware) {
                $guard = trim(Str::before($guard, ','));
                $schemes = (array) config('documentator.security', []);

                if ($guard !== '' && array_key_exists($guard, $schemes)) {
                    return $guard;
                }
            }

            return 'default';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function securityScopes(Route $route): array
    {
        $scopes = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! Str::startsWith($middleware, ['abilities:', 'ability:', 'scopes:', 'scope:'])) {
                continue;
            }

            foreach (explode(',', Str::after($middleware, ':')) as $scope) {
                $scope = trim($scope);

                if ($scope !== '') {
                    $scopes[] = $scope;
                }
            }
        }

        return array_values(array_unique($scopes));
    }
}
