<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tsitsishvili\Documentator\Documentator;
use Tsitsishvili\Documentator\OpenApi\OpenApiSections;

/**
 * Pre-builds the OpenAPI document and writes it to disk so the docs route can
 * serve it without re-scanning routes on every request (enable via config).
 */
final class GenerateCommand extends Command
{
    protected $signature = 'documentator:generate {--path= : Override the output path for the OpenAPI JSON}';

    protected $description = 'Generate the OpenAPI document and cache it to disk';

    public function handle(Documentator $documentator, OpenApiSections $sections): int
    {
        $spec = $documentator->toOpenApi();

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
