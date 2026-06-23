<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the built-in UI's static assets straight from the package, so the host
 * app doesn't have to publish anything. Only the whitelisted files are exposed.
 */
final class AssetController
{
    private const ASSETS = [
        'app.css' => 'text/css; charset=utf-8',
        'app.js' => 'text/javascript; charset=utf-8',
    ];

    public function show(string $asset): Response
    {
        abort_unless(array_key_exists($asset, self::ASSETS), 404);

        $path = __DIR__.'/../../../resources/ui/'.$asset;

        abort_unless(is_file($path), 404);

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => self::ASSETS[$asset],
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
