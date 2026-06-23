<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tsitsishvili\Documentator\Documentator;

/**
 * Writes the OpenAPI document to a file for external tooling — CI drift checks,
 * Postman/Insomnia import, client generation, etc.
 */
final class ExportCommand extends Command
{
    protected $signature = 'documentator:export {path? : Output path (defaults to openapi.json in the project root)}';

    protected $description = 'Export the OpenAPI document to a JSON file';

    public function handle(Documentator $documentator): int
    {
        $path = $this->argument('path') ?: base_path('openapi.json');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(
            $documentator->toOpenApi(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $this->info("Exported OpenAPI document to {$path}");

        return self::SUCCESS;
    }
}
