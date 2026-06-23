<?php

declare(strict_types=1);

it('serves the built-in explorer shell by default', function () {
    $response = $this->get('/docs');

    $response->assertOk();
    expect($response->getContent())
        ->toContain('id="app"')
        ->toContain('/docs/assets/app.js')
        ->toContain('openapi.json');
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
