<?php

declare(strict_types=1);

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

class CheckedResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => (int) $this->id];
    }
}

class CheckedController
{
    public function index(): CheckedResource
    {
        return new CheckedResource((object) ['id' => 1]);
    }

    public function raw(): void
    {
        //
    }
}

it('passes when every endpoint is well documented', function () {
    Route::get('api/checked', [CheckedController::class, 'index']);

    $this->artisan('documentator:check')
        ->expectsOutputToContain('No documentation issues found.')
        ->assertExitCode(0);
});

it('flags a closure route and a missing success schema', function () {
    Route::get('api/closure', fn () => 'x');
    Route::get('api/raw', [CheckedController::class, 'raw']);

    $this->artisan('documentator:check')
        ->expectsOutputToContain('closure route')
        ->expectsOutputToContain('no success response schema')
        ->assertExitCode(0); // reports, but does not fail without --strict
});

it('fails under --strict when issues exist', function () {
    Route::get('api/closure', fn () => 'x');

    $this->artisan('documentator:check', ['--strict' => true])->assertExitCode(1);
});

it('detects drift from a committed spec', function () {
    Route::get('api/checked', [CheckedController::class, 'index']);

    $path = sys_get_temp_dir().'/documentator-check-'.uniqid().'.json';

    // A freshly exported spec matches.
    $this->artisan('documentator:export', ['path' => $path])->assertExitCode(0);
    $this->artisan('documentator:check', ['--against' => $path])->assertExitCode(0);

    // Mutate the committed spec → drift is detected.
    file_put_contents($path, json_encode(['openapi' => '3.1.0', 'paths' => []]));
    $this->artisan('documentator:check', ['--against' => $path])
        ->expectsOutputToContain('drifted')
        ->assertExitCode(1);

    @unlink($path);
});
