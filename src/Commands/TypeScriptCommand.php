<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tsitsishvili\Documentator\Documentator;
use Tsitsishvili\Documentator\OpenApi\OpenApiCompatibilityException;
use Tsitsishvili\Documentator\TypeScript\TypeScriptClientGenerator;

/**
 * Writes a dependency-free TypeScript fetch client from the generated contract.
 */
final class TypeScriptCommand extends Command
{
    protected $signature = 'documentator:typescript
        {path? : Output path (defaults to documentator-client.ts)}
        {--name=DocumentatorClient : Generated client class name}
        {--openapi= : Target OpenAPI version (3.1 or 3.2)}
        {--omit-unsupported : Explicitly omit constructs unavailable in the target version}';

    protected $description = 'Generate a dependency-free TypeScript fetch client';

    public function handle(Documentator $documentator, TypeScriptClientGenerator $typescript): int
    {
        $path = $this->argument('path') ?: base_path('documentator-client.ts');

        try {
            $spec = $documentator->toOpenApi(
                $this->option('openapi'),
                (bool) $this->option('omit-unsupported'),
            );
        } catch (InvalidArgumentException|OpenApiCompatibilityException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $typescript->generate($spec, (string) $this->option('name')));
        $this->info("Generated TypeScript client at {$path}");

        return self::SUCCESS;
    }
}
