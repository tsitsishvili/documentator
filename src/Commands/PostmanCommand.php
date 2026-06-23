<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tsitsishvili\Documentator\Documentator;
use Tsitsishvili\Documentator\Postman\PostmanGenerator;

/**
 * Exports the API as a Postman Collection v2.1 file.
 */
final class PostmanCommand extends Command
{
    protected $signature = 'documentator:postman {path? : Output path (defaults to postman-collection.json in the project root)}';

    protected $description = 'Export the API as a Postman collection';

    public function handle(Documentator $documentator, PostmanGenerator $postman): int
    {
        $path = $this->argument('path') ?: base_path('postman-collection.json');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(
            $postman->generate($documentator->toOpenApi()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $this->info("Exported Postman collection to {$path}");

        return self::SUCCESS;
    }
}
