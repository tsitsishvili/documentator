<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tsitsishvili\Documentator\Documentator;
use Tsitsishvili\Documentator\OpenApi\OpenApiCompatibilityException;
use Tsitsishvili\Documentator\OpenApi\OpenApiSections;

/**
 * Pre-builds the OpenAPI document and writes it to disk so the docs route can
 * serve it without re-scanning routes on every request (enable via config).
 */
final class GenerateCommand extends Command
{
    protected $signature = 'documentator:generate
        {--path= : Override the output path for the OpenAPI JSON}
        {--openapi= : Target OpenAPI version (3.1 or 3.2)}
        {--omit-unsupported : Explicitly omit constructs unavailable in the target version}';

    protected $description = 'Generate the OpenAPI document and cache it to disk';

    public function handle(Documentator $documentator, OpenApiSections $sections): int
    {
        try {
            $spec = $documentator->toOpenApi(
                $this->option('openapi'),
                (bool) $this->option('omit-unsupported'),
            );
        } catch (InvalidArgumentException|OpenApiCompatibilityException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $path = $this->option('path') ?: config('documentator.cache.path');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $pathCount = count($spec['paths'] ?? []);
        $this->info("Wrote OpenAPI document ({$pathCount} paths) to {$path}");

        foreach ($sections->split($spec) as $slug => $sectionSpec) {
            $sectionPath = $sections->cachePath($path, $slug);

            File::put($sectionPath, json_encode($sectionSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $sectionPathCount = count($sectionSpec['paths'] ?? []);
            $label = $sectionSpec['x-documentator-section'] ?? $slug;
            $this->info("Wrote {$label} OpenAPI document ({$sectionPathCount} paths) to {$sectionPath}");
        }

        return self::SUCCESS;
    }
}
