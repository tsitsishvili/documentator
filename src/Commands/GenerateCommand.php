<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tsitsishvili\Documentator\Documentator;

/**
 * Pre-builds the OpenAPI document and writes it to disk so the docs route can
 * serve it without re-scanning routes on every request (enable via config).
 */
final class GenerateCommand extends Command
{
    protected $signature = 'documentator:generate {--path= : Override the output path for the OpenAPI JSON}';

    protected $description = 'Generate the OpenAPI document and cache it to disk';

    public function handle(Documentator $documentator): int
    {
        $spec = $documentator->toOpenApi();

        $path = $this->option('path') ?: config('documentator.cache.path');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $pathCount = count($spec['paths'] ?? []);
        $this->info("Wrote OpenAPI document ({$pathCount} paths) to {$path}");

        return self::SUCCESS;
    }
}
