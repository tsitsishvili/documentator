<?php

declare(strict_types=1);

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Artisan;
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
    /**
     * List checked resources.
     *
     * Returns a typed resource so the generated success response has a schema.
     */
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

it('passes when a closure route has an inferred success schema', function () {
    Route::get('api/closure', fn (): CheckedResource => new CheckedResource((object) ['id' => 1]));

    $this->artisan('documentator:check')
        ->expectsOutputToContain('No documentation issues found.')
        ->assertExitCode(0);
});

it('flags routes missing success schemas', function () {
    Route::get('api/closure', fn () => 'x');
    Route::get('api/raw', [CheckedController::class, 'raw']);

    $this->artisan('documentator:check')
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

it('prints a documentation health summary', function () {
    Route::get('api/raw', [CheckedController::class, 'raw']);

    $this->artisan('documentator:check')
        ->expectsOutputToContain('Documentation health:')
        ->expectsOutputToContain('operation(s) missing descriptions')
        ->expectsOutputToContain('generic 200 success response(s)')
        ->assertExitCode(0);
});

it('emits dashboard-friendly json', function () {
    Route::get('api/closure', fn () => 'x');
    Route::get('api/checked', [CheckedController::class, 'index']);

    $exit = Artisan::call('documentator:check', ['--json' => true, '--strict' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['ok'])->toBeFalse()
        ->and($payload['issues'][0]['message'])->toContain('no success response schema')
        ->and($payload['health']['operations'])->toBe(2)
        ->and($payload)->toHaveKeys(['validation_errors', 'drift', 'hidden_suggestions']);
});

it('suggests suspicious routes that may be hidden', function () {
    Route::get('api/internal/reindex', [CheckedController::class, 'index'])->name('internal.reindex');
    Route::get('api/public/checked', [CheckedController::class, 'index']);

    $this->artisan('documentator:check', ['--suggest-hidden' => true])
        ->expectsOutputToContain('may belong behind #[Hidden]')
        ->expectsOutputToContain('GET /api/internal/reindex')
        ->assertExitCode(0);

    $exit = Artisan::call('documentator:check', ['--suggest-hidden' => true, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload['hidden_suggestions'][0]['endpoint'])->toBe('GET /api/internal/reindex');
});
