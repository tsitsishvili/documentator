<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

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

it('hides the docs when access is disabled', function () {
    config(['documentator.enabled' => false]);

    $this->get('/docs')->assertNotFound();
    $this->get('/docs/openapi.json')->assertNotFound();
});

it('serves the docs when access is explicitly enabled', function () {
    config(['documentator.enabled' => true]);

    $this->get('/docs')->assertOk();
});
