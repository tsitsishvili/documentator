<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Documentator;
use Tsitsishvili\Documentator\OpenApi\OpenApiGenerator;
use Tsitsishvili\Documentator\OpenApi\OpenApiMethods;
use Tsitsishvili\Documentator\Support\OpenApiDiff;
use Tsitsishvili\Documentator\Support\OpenApiValidator;

/**
 * Audits the inferred documentation so CI can catch gaps and drift: endpoints
 * whose actions can't be introspected or that document only a bare success
 * response with no schema. With --against it also fails when a committed spec
 * no longer matches what the package would generate.
 */
final class CheckCommand extends Command
{
    protected $signature = 'documentator:check
        {--strict : Exit non-zero when any documentation issue is found}
        {--against= : Path to a committed OpenAPI JSON; fail if the generated spec drifts from it}
        {--fail-on=any : Drift policy: any or breaking}
        {--json : Emit machine-readable JSON for CI dashboards}
        {--suggest-hidden : Suggest suspicious internal routes that may belong behind #[Hidden] or route excludes}';

    protected $description = 'Audit the generated API documentation for gaps and drift';

    public function handle(Documentator $documentator, OpenApiGenerator $generator): int
    {
        if (! in_array($this->failOn(), ['any', 'breaking'], true)) {
            $this->error('--fail-on must be either "any" or "breaking".');

            return self::FAILURE;
        }

        $endpoints = $documentator->endpoints();
        $spec = $generator->generate($endpoints);
        $issues = $this->audit($endpoints);
        $health = $this->health($spec);
        $validationErrors = OpenApiValidator::validate($spec);
        $drift = $this->drift($spec);
        $hiddenSuggestions = $this->option('suggest-hidden') ? $this->hiddenSuggestions($endpoints) : [];

        if ($this->option('json')) {
            $failed = $validationErrors !== []
                || $drift['should_fail']
                || ($issues !== [] && $this->option('strict'));

            $this->line((string) json_encode([
                'ok' => ! $failed,
                'issues' => $this->issuePayload($issues),
                'health' => $health,
                'validation_errors' => $validationErrors,
                'drift' => $drift,
                'hidden_suggestions' => $this->suggestionPayload($hiddenSuggestions),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        foreach ($issues as [$endpoint, $message]) {
            $this->warn("  {$endpoint}  —  {$message}");
        }

        if ($issues === []) {
            $this->info('No documentation issues found.');
        } else {
            $this->newLine();
            $this->warn(count($issues).' documentation issue(s) found.');
        }

        $this->reportHealth($health);

        foreach ($validationErrors as $error) {
            $this->error("  {$error}");
        }

        if ($validationErrors === []) {
            $this->info('Documentator OpenAPI checks passed.');
        } else {
            $this->newLine();
            $this->error(count($validationErrors).' OpenAPI validation error(s) found.');
        }

        $this->reportHiddenSuggestions($hiddenSuggestions);
        $this->reportDriftResult($drift);

        if ($validationErrors !== [] || $drift['should_fail'] || ($issues !== [] && $this->option('strict'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, EndpointData>  $endpoints
     * @return array<int, array{0: string, 1: string}>
     */
    private function audit(array $endpoints): array
    {
        $issues = [];

        foreach ($endpoints as $endpoint) {
            $label = strtoupper(implode('|', $endpoint->verbs())).' /'.ltrim($endpoint->uri, '/');

            if (! $endpoint->introspectable) {
                $issues[] = [$label, 'route action cannot be introspected; move it to a controller method or closure to document it'];

                continue;
            }

            if (! $this->hasSuccessSchema($endpoint)) {
                $issues[] = [$label, 'no success response schema (document the return type or add #[Response])'];
            }
        }

        return $issues;
    }

    private function hasSuccessSchema(EndpointData $endpoint): bool
    {
        foreach ($endpoint->responses as $response) {
            if ($response->status >= 200 && $response->status < 300
                && ($response->schema !== null || $response->example !== null || $response->resource !== null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function reportHealth(array $health): void
    {
        $this->newLine();
        $this->info('Documentation health:');
        $this->line("  Operations: {$health['operations']}");
        $this->line("  Tags: {$health['tags']} ({$health['singleton_tags']} single-endpoint)");
        $this->line("  Secured operations: {$health['secured_operations']}");

        $warnings = [
            'missing_summaries' => 'operation(s) missing summaries',
            'generic_summaries' => 'operation(s) with generic summaries',
            'missing_descriptions' => 'operation(s) missing descriptions',
            'generic_successes' => 'generic 200 success response(s)',
        ];

        foreach ($warnings as $key => $label) {
            if ($health[$key] > 0) {
                $this->warn("  {$health[$key]} {$label}.");
            }
        }

        if ($health['security_schemes'] > 0 && $health['secured_operations'] === 0 && ! $health['global_security']) {
            $this->warn('  Security schemes are configured, but no secured operations were inferred.');
        }

        if ($health['tags'] > 0 && $health['singleton_tags'] / max($health['tags'], 1) >= 0.6 && $health['singleton_tags'] >= 10) {
            $this->warn('  Most tags contain only one endpoint; consider path grouping, sections, or #[Group] attributes.');
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, int|bool>
     */
    private function health(array $spec): array
    {
        $operations = 0;
        $missingSummaries = 0;
        $genericSummaries = 0;
        $missingDescriptions = 0;
        $genericSuccesses = 0;
        $securedOperations = 0;
        $tagCounts = [];
        $rootSecurity = (array) ($spec['security'] ?? []);

        foreach ((array) ($spec['paths'] ?? []) as $methods) {
            foreach ((array) $methods as $method => $operation) {
                if (! is_array($operation) || ! in_array(strtolower((string) $method), OpenApiMethods::ALL, true)) {
                    continue;
                }

                $operations++;
                $summary = trim((string) ($operation['summary'] ?? ''));
                $description = trim((string) ($operation['description'] ?? ''));

                if ($summary === '') {
                    $missingSummaries++;
                } elseif ($this->isGenericSummary($summary)) {
                    $genericSummaries++;
                }

                if ($description === '') {
                    $missingDescriptions++;
                }

                $operationSecurity = $operation['security'] ?? null;
                if ((is_array($operationSecurity) && $operationSecurity !== []) || ($operationSecurity === null && $rootSecurity !== [])) {
                    $securedOperations++;
                }

                foreach ((array) ($operation['tags'] ?? ['Endpoints']) as $tag) {
                    $tagCounts[(string) $tag] = ($tagCounts[(string) $tag] ?? 0) + 1;
                }

                foreach ((array) ($operation['responses'] ?? []) as $code => $response) {
                    if ((string) $code === '200'
                        && is_array($response)
                        && ($response['description'] ?? null) === 'Successful response'
                        && ! isset($response['content'])) {
                        $genericSuccesses++;
                    }
                }
            }
        }

        return [
            'operations' => $operations,
            'tags' => count($tagCounts),
            'singleton_tags' => count(array_filter($tagCounts, fn (int $count) => $count === 1)),
            'missing_summaries' => $missingSummaries,
            'generic_summaries' => $genericSummaries,
            'missing_descriptions' => $missingDescriptions,
            'generic_successes' => $genericSuccesses,
            'security_schemes' => count((array) ($spec['components']['securitySchemes'] ?? [])),
            'secured_operations' => $securedOperations,
            'global_security' => $rootSecurity !== [],
        ];
    }

    private function isGenericSummary(string $summary): bool
    {
        return (bool) preg_match('/^(Index|Show|Store|Update|Destroy|Create|Get|Post|Put|Patch|Delete|Handle|Invoke|Emit)$/i', $summary);
    }

    /**
     * @param  array<string, mixed>  $actualSpec
     */
    private function drift(array $actualSpec): array
    {
        $path = $this->option('against');
        $failOn = $this->failOn();

        if ($path === null) {
            return [
                'checked' => false,
                'path' => null,
                'fail_on' => $failOn,
                'missing' => false,
                'drifted' => false,
                'breaking' => false,
                'should_fail' => false,
                'changes' => [],
            ];
        }

        if (! File::exists($path)) {
            return [
                'checked' => true,
                'path' => $path,
                'fail_on' => $failOn,
                'missing' => true,
                'drifted' => true,
                'breaking' => true,
                'should_fail' => true,
                'changes' => [],
            ];
        }

        $expectedSpec = json_decode(File::get($path), true);
        $expected = json_encode($expectedSpec);
        $actual = json_encode($actualSpec);

        $drifted = $expected !== $actual;
        $changes = $drifted
            ? OpenApiDiff::compare(is_array($expectedSpec) ? $expectedSpec : [], $actualSpec)
            : [];
        $breaking = collect($changes)->contains(fn (array $change): bool => $change['severity'] === 'breaking');

        return [
            'checked' => true,
            'path' => $path,
            'fail_on' => $failOn,
            'missing' => false,
            'drifted' => $drifted,
            'breaking' => $breaking,
            'should_fail' => $drifted && ($failOn === 'any' || $breaking),
            'changes' => $changes,
        ];
    }

    private function failOn(): string
    {
        return strtolower((string) ($this->option('fail-on') ?: 'any'));
    }

    /**
     * @param  array{checked: bool, path: string|null, fail_on: string, missing: bool, drifted: bool, breaking: bool, should_fail: bool, changes: array<int, array<string, string>>}  $drift
     */
    private function reportDriftResult(array $drift): void
    {
        if (! $drift['checked']) {
            return;
        }

        if ($drift['missing']) {
            $this->error("Spec file not found: {$drift['path']}");

            return;
        }

        if ($drift['drifted']) {
            if ($drift['should_fail']) {
                $this->error("Generated spec has drifted from {$drift['path']}. Re-run documentator:export and commit the result.");
            } else {
                $this->warn("Generated spec has non-breaking drift from {$drift['path']}; allowed by --fail-on=breaking.");
            }
            $this->reportDriftChanges($drift['changes']);

            return;
        }

        $this->info("Spec matches {$drift['path']}.");
    }

    /**
     * @param  array<int, array<string, string>>  $changes
     */
    private function reportDriftChanges(array $changes): void
    {
        if ($changes === []) {
            $this->warn('  The JSON changed, but no path/operation/response drift was detected.');

            return;
        }

        $this->newLine();
        $this->warn('Contract changes:');

        foreach (array_slice($changes, 0, 20) as $change) {
            $this->line("  [{$change['severity']}] {$change['location']} — {$change['message']}");
        }

        if (count($changes) > 20) {
            $this->line('  ... and '.(count($changes) - 20).' more change(s).');
        }
    }

    /**
     * @param  array<int, EndpointData>  $endpoints
     * @return array<int, array{endpoint: string, reason: string, recommendation: string}>
     */
    private function hiddenSuggestions(array $endpoints): array
    {
        $suggestions = [];

        foreach ($endpoints as $endpoint) {
            $haystack = strtolower(implode(' ', array_filter([
                $endpoint->uri,
                $endpoint->routeName,
                $endpoint->controller,
                $endpoint->method,
                $endpoint->group,
                $endpoint->summary,
            ])));

            $reason = match (true) {
                (bool) preg_match('~(^|[/.:-])(internal|private|admin|debug|diagnostics?|ops|staff)([/.:-]|$)~', $haystack) => 'name or URI looks internal',
                (bool) preg_match('~(^|[/.:-])(health|metrics|status|ping|heartbeat|queue|jobs|scheduler)([/.:-]|$)~', $haystack) => 'operational endpoint is exposed in docs',
                Str::contains($haystack, ['telescope', 'horizon', 'pulse', '_debugbar']) => 'Laravel tooling route is exposed in docs',
                default => null,
            };

            if ($reason === null) {
                continue;
            }

            $suggestions[] = [
                'endpoint' => strtoupper(implode('|', $endpoint->verbs())).' /'.ltrim($endpoint->uri, '/'),
                'reason' => $reason,
                'recommendation' => 'Add #[Hidden] or documentator.routes.exclude / exclude_middleware if this route is not public API surface.',
            ];
        }

        return $suggestions;
    }

    /**
     * @param  array<int, array{endpoint: string, reason: string, recommendation: string}>  $suggestions
     */
    private function reportHiddenSuggestions(array $suggestions): void
    {
        if ($suggestions === []) {
            return;
        }

        $this->newLine();
        $this->warn(count($suggestions).' route(s) may belong behind #[Hidden] or route excludes:');

        foreach ($suggestions as $suggestion) {
            $this->line("  {$suggestion['endpoint']}  —  {$suggestion['reason']}");
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $issues
     * @return array<int, array{endpoint: string, message: string}>
     */
    private function issuePayload(array $issues): array
    {
        return array_map(
            fn (array $issue): array => ['endpoint' => $issue[0], 'message' => $issue[1]],
            $issues,
        );
    }

    /**
     * @param  array<int, array{endpoint: string, reason: string, recommendation: string}>  $suggestions
     * @return array<int, array{endpoint: string, reason: string, recommendation: string}>
     */
    private function suggestionPayload(array $suggestions): array
    {
        return array_values($suggestions);
    }
}
