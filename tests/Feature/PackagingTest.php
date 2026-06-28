<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\OpenApi\OpenApiSections;

it('exports the OpenAPI document to a file', function () {
    Route::get('api/ping', fn () => 'pong');

    $path = sys_get_temp_dir().'/documentator-export-'.uniqid().'.json';

    $this->artisan('documentator:export', ['path' => $path])->assertExitCode(0);

    expect(is_file($path))->toBeTrue();

    $spec = json_decode((string) file_get_contents($path), true);
    expect($spec['openapi'])->toBe('3.1.0')
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

it('hides the docs when access is disabled', function () {
    config(['documentator.enabled' => false]);

    $this->get('/docs')->assertNotFound();
    $this->get('/docs/openapi.json')->assertNotFound();
});

it('serves the docs when access is explicitly enabled', function () {
    config(['documentator.enabled' => true]);

    $this->get('/docs')->assertOk();
});
