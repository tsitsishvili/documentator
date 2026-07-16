<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Tsitsishvili\Documentator\Documentator;
use Tsitsishvili\Documentator\DocumentatorServiceProvider;
use Tsitsishvili\Documentator\OpenApi\OpenApiSections;

it('exports the OpenAPI document to a file', function () {
    Route::get('api/ping', fn () => 'pong');

    $path = sys_get_temp_dir().'/documentator-export-'.uniqid().'.json';

    $this->artisan('documentator:export', ['path' => $path])->assertExitCode(0);

    expect(is_file($path))->toBeTrue();

    $spec = json_decode((string) file_get_contents($path), true);
    expect($spec['openapi'])->toBe('3.2.0')
        ->and($spec['paths'])->toHaveKey('/api/ping');

    @unlink($path);
});

it('generates split cached OpenAPI documents for configured sections', function () {
    config([
        'documentator.routes.match' => ['api/*', 'app/*'],
        'documentator.grouping.sections' => [
            'api' => 'API',
            'app' => 'App',
        ],
    ]);

    Route::get('api/ping', fn () => 'pong');
    Route::get('app/ping', fn () => 'pong');

    $path = sys_get_temp_dir().'/documentator-cache-'.uniqid().'/openapi.json';

    $this->artisan('documentator:generate', ['--path' => $path])->assertExitCode(0);

    $sections = app(OpenApiSections::class);
    $apiPath = $sections->cachePath($path, 'api');
    $appPath = $sections->cachePath($path, 'app');

    expect(is_file($path))->toBeTrue()
        ->and(is_file($apiPath))->toBeTrue()
        ->and(is_file($appPath))->toBeTrue();

    $api = json_decode((string) file_get_contents($apiPath), true);
    $app = json_decode((string) file_get_contents($appPath), true);

    expect($api['paths'])->toHaveKey('/api/ping')
        ->and($api['paths'])->not->toHaveKey('/app/ping')
        ->and($app['paths'])->toHaveKey('/app/ping')
        ->and($app['paths'])->not->toHaveKey('/api/ping');

    @unlink($path);
    @unlink($apiPath);
    @unlink($appPath);
    @rmdir(dirname($path));
});

it('keeps QUERY operations in split OpenAPI documents', function () {
    config([
        'documentator.grouping.sections' => ['api' => 'API'],
    ]);

    Route::match(['QUERY'], 'api/search', fn () => []);

    $sections = app(OpenApiSections::class)->split(app(Documentator::class)->toOpenApi());

    expect($sections['api']['paths']['/api/search'])->toHaveKey('query');
});

it('ships AI agent guidance files with the package', function () {
    $root = dirname(__DIR__, 2);

    expect(is_file($root.'/resources/boost/guidelines/core.blade.php'))->toBeTrue()
        ->and(is_file($root.'/resources/boost/skills/documentator-api-docs/SKILL.md'))->toBeTrue()
        ->and(is_file($root.'/resources/ai/guidelines/documentator.md'))->toBeTrue()
        ->and(is_file($root.'/resources/ai/cursor/documentator.mdc'))->toBeTrue()
        ->and(is_file($root.'/resources/ai/gemini/documentator.md'))->toBeTrue()
        ->and(is_file($root.'/resources/ai/codex/documentator.md'))->toBeTrue()
        ->and(is_file($root.'/UPGRADING.md'))->toBeTrue();

    expect((string) file_get_contents($root.'/UPGRADING.md'))
        ->toContain('## Upgrading from 1.x to 2.0')
        ->toContain('OpenAPI 3.2')
        ->toContain('documentator:check --against=openapi-v1.json')
        ->toContain("Route::match(['QUERY']");

    $skill = (string) file_get_contents($root.'/resources/boost/skills/documentator-api-docs/SKILL.md');
    expect($skill)->toStartWith('---')
        ->and($skill)->toContain('name: documentator-api-docs')
        ->and($skill)->toContain('description:');

    $attributeNames = array_map(
        fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
        glob($root.'/src/Attributes/*.php') ?: [],
    );

    expect($attributeNames)->not->toBeEmpty();

    foreach ($attributeNames as $attributeName) {
        expect($skill)->toContain($attributeName);
    }

    $shortGuidanceFiles = [
        $root.'/resources/boost/guidelines/core.blade.php',
        $root.'/resources/ai/guidelines/documentator.md',
        $root.'/resources/ai/cursor/documentator.mdc',
        $root.'/resources/ai/gemini/documentator.md',
        $root.'/resources/ai/codex/documentator.md',
    ];

    foreach ($shortGuidanceFiles as $path) {
        $guidance = (string) file_get_contents($path);

        foreach ([
            'OpenAPI 3.2',
            "Route::match(['QUERY']",
            '`GET`',
            '`HEAD`',
            'documentator:explain',
            'documentator:check',
        ] as $coreInstruction) {
            expect($guidance)->toContain($coreInstruction);
        }
    }

    expect((string) file_get_contents($root.'/resources/ai/guidelines/documentator.md'))
        ->toContain('## Portable workflow')
        ->not->toContain('Generation never throws')
        ->and((string) file_get_contents($root.'/resources/ai/codex/documentator.md'))
        ->toContain('## Codex workflow')
        ->and((string) file_get_contents($root.'/resources/ai/gemini/documentator.md'))
        ->toContain('## Gemini context-gathering sequence')
        ->and((string) file_get_contents($root.'/resources/ai/cursor/documentator.mdc'))
        ->toContain('globs: app/**/*.php,routes/**/*.php,config/documentator.php')
        ->and((string) file_get_contents($root.'/resources/boost/guidelines/core.blade.php'))
        ->toContain('Use the **`documentator-api-docs`** skill')
        ->and($skill)
        ->not->toContain('## When to use this skill');
});

it('excludes development-only files from release archives', function () {
    $root = dirname(__DIR__, 2);
    $attributes = (string) file_get_contents($root.'/.gitattributes');

    expect($attributes)
        ->toContain('/vendor export-ignore')
        ->toContain('/node_modules export-ignore')
        ->toContain('/tests export-ignore')
        ->toContain('/test-results export-ignore')
        ->toContain('/.idea export-ignore')
        ->toContain('/.phpstan export-ignore')
        ->not->toContain('/resources export-ignore')
        ->not->toContain('/src export-ignore')
        ->not->toContain('/UPGRADING.md export-ignore');
});

it('publishes AI guidance to native agent locations under the documentator-ai tag', function () {
    $normalized = [];
    foreach (ServiceProvider::pathsToPublish(DocumentatorServiceProvider::class, 'documentator-ai') as $source => $destination) {
        $normalized[realpath($source) ?: $source] = $destination;
    }

    $root = dirname(__DIR__, 2);

    expect($normalized)->toBe([
        realpath($root.'/resources/boost/skills/documentator-api-docs') => base_path('.claude/skills/documentator-api-docs'),
        realpath($root.'/resources/ai/guidelines/documentator.md') => base_path('.ai/guidelines/documentator.md'),
        realpath($root.'/resources/ai/cursor/documentator.mdc') => base_path('.cursor/rules/documentator.mdc'),
        realpath($root.'/resources/ai/gemini/documentator.md') => base_path('GEMINI.md'),
        realpath($root.'/resources/ai/codex/documentator.md') => base_path('AGENTS.md'),
    ]);
});

it('hides the docs when access is disabled', function () {
    config(['documentator.enabled' => false]);

    $this->get('/docs')->assertNotFound();
    $this->get('/docs/openapi.json')->assertNotFound();
});

it('hides the docs when access is not explicitly enabled', function () {
    config(['documentator.enabled' => null]);

    $this->get('/docs')->assertNotFound();
    $this->get('/docs/openapi.json')->assertNotFound();
});

it('serves the docs when access is explicitly enabled', function () {
    config(['documentator.enabled' => true]);

    $this->get('/docs')->assertOk();
});
