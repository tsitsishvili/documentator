<?php

declare(strict_types=1);

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

it('serves the built-in CSS and JS assets', function () {
    $this->get('/docs/assets/app.css')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=utf-8');

    $this->get('/docs/assets/app.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
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
