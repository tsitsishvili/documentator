<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tsitsishvili\Documentator\Data\EndpointData;
use Tsitsishvili\Documentator\Documentator;

/**
 * Audits the inferred documentation so CI can catch gaps and drift: endpoints
 * that can't be introspected (closure routes) or document only a bare success
 * response with no schema. With --against it also fails when a committed spec no
 * longer matches what the package would generate.
 */
final class CheckCommand extends Command
{
    protected $signature = 'documentator:check
        {--strict : Exit non-zero when any documentation issue is found}
        {--against= : Path to a committed OpenAPI JSON; fail if the generated spec drifts from it}';

    protected $description = 'Audit the generated API documentation for gaps and drift';

    public function handle(Documentator $documentator): int
    {
        $issues = $this->audit($documentator->endpoints());

        foreach ($issues as [$endpoint, $message]) {
            $this->warn("  {$endpoint}  —  {$message}");
        }

        if ($issues === []) {
            $this->info('No documentation issues found.');
        } else {
            $this->newLine();
            $this->warn(count($issues).' documentation issue(s) found.');
        }

        $drifted = $this->checkDrift($documentator);

        if ($drifted || ($issues !== [] && $this->option('strict'))) {
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

            if ($endpoint->controller === null) {
                $issues[] = [$label, 'closure route — cannot introspect; move it to a controller method to document it'];

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

    private function checkDrift(Documentator $documentator): bool
    {
        $path = $this->option('against');

        if ($path === null) {
            return false;
        }

        if (! File::exists($path)) {
            $this->error("Spec file not found: {$path}");

            return true;
        }

        $expected = json_encode(json_decode(File::get($path), true));
        $actual = json_encode($documentator->toOpenApi());

        if ($expected !== $actual) {
            $this->error("Generated spec has drifted from {$path}. Re-run documentator:export and commit the result.");

            return true;
        }

        $this->info("Spec matches {$path}.");

        return false;
    }
}
