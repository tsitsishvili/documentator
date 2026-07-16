<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tsitsishvili\Documentator\Documentator;

afterEach(function () {
    // The auth gate is static state; reset it so it can't leak between tests.
    Documentator::auth(fn () => true);
});

it('serves the built-in explorer shell by default', function () {
    $response = $this->get('/docs');

    $response->assertOk();
    expect($response->getContent())
        ->toContain('id="app"')
        ->toContain('/docs/assets/app.js')
        ->toContain('openapi.json');
});

it('passes the configured auth storage mode to the built-in explorer', function () {
    config(['documentator.ui.auth_storage' => 'session']);

    $response = $this->get('/docs');

    $response->assertOk();
    expect($response->getContent())->toContain('authStorage: "session"');
});

it('uses memory auth storage by default', function () {
    $response = $this->get('/docs');

    $response->assertOk();
    expect($response->getContent())->toContain('authStorage: "memory"');
});

it('serves the built-in CSS and JS assets', function () {
    $this->get('/docs/assets/app.css')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=utf-8');

    $this->get('/docs/assets/app.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8');

    $this->get('/docs/assets/core.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8');

    $this->get('/docs/assets/snippets.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
});

it('ships persistent filters and virtualized sidebar assets', function () {
    $entry = $this->get('/docs/assets/app.js')
        ->assertOk()
        ->getContent();
    $core = $this->get('/docs/assets/core.js')
        ->assertOk()
        ->getContent();
    $css = $this->get('/docs/assets/app.css')
        ->assertOk()
        ->getContent();

    expect($entry)
        ->toContain('core.js')
        ->toContain('snippets.js')
        ->and($core)
        ->toContain('collapsedGroups')
        ->toContain("store.set('method'")
        ->toContain('renderVirtualNav')
        ->toContain('NAV_OVERSCAN')
        ->toContain("'query'")
        ->toContain('query: 1')
        ->not->toContain('All sections')
        ->and($css)
        ->toContain('.nav__spacer')
        ->toContain('contain: layout style paint');
});

it('serves configured sections on separate paths with split OpenAPI documents', function () {
    config([
        'documentator.routes.match' => ['api/*', 'app/*'],
        'documentator.grouping.sections' => [
            'api' => 'API',
            'app' => 'App',
        ],
    ]);

    Route::get('api/ping', fn () => 'pong');
    Route::get('app/ping', fn () => 'pong');

    $this->get('/docs')->assertRedirect('/docs/api');
    $this->get('/docs/missing')->assertNotFound();

    $shell = $this->get('/docs/api')->assertOk()->getContent();

    expect($shell)
        ->toContain('docs\/api\/openapi.json')
        ->toContain('"label":"API"')
        ->toContain('"label":"App"');

    $api = $this->getJson('/docs/api/openapi.json')->assertOk()->json();
    $app = $this->getJson('/docs/app/openapi.json')->assertOk()->json();

    expect($api['x-documentator-section'])->toBe('API')
        ->and($api['paths'])->toHaveKey('/api/ping')
        ->and($api['paths'])->not->toHaveKey('/app/ping')
        ->and($app['x-documentator-section'])->toBe('App')
        ->and($app['paths'])->toHaveKey('/app/ping')
        ->and($app['paths'])->not->toHaveKey('/api/ping');
});

it('rejects asset names outside the whitelist', function () {
    $this->get('/docs/assets/secrets.env')->assertNotFound();
});

it('embeds Scalar when the driver is switched', function () {
    config(['documentator.ui.driver' => 'scalar']);

    $response = $this->get('/docs');

    $response->assertOk();
    expect($response->getContent())->toContain('id="api-reference"');
});

it('forbids docs access when the auth gate denies it', function () {
    Documentator::auth(fn () => false);

    $this->get('/docs')->assertForbidden();
    $this->get('/docs/openapi.json')->assertForbidden();
});

it('passes the request to the auth gate', function () {
    Documentator::auth(fn ($request) => $request->query('token') === 'secret');

    $this->get('/docs')->assertForbidden();
    $this->get('/docs?token=secret')->assertOk();
});
