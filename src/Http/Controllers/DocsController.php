<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * Serves the docs UI: the built-in Aurora explorer by default, or the
 * Scalar embed when config('documentator.ui.driver') is "scalar".
 */
final class DocsController
{
    public function __construct(private readonly Factory $views) {}

    public function index(): View
    {
        if (config('documentator.ui.driver') === 'scalar') {
            return $this->views->make('documentator::scalar', [
                'title' => config('documentator.title'),
                'specUrl' => route('documentator.openapi'),
                'assets' => config('documentator.ui.assets'),
            ]);
        }

        return $this->views->make('documentator::docs', [
            'title' => config('documentator.title'),
            'specUrl' => route('documentator.openapi'),
            'authStorage' => config('documentator.ui.auth_storage', 'local'),
            'cssUrl' => $this->assetUrl('app.css'),
            'jsUrl' => $this->assetUrl('app.js'),
        ]);
    }

    private function assetUrl(string $asset): string
    {
        $path = __DIR__.'/../../../resources/ui/'.$asset;

        return route('documentator.asset', $asset).'?v='.(is_file($path) ? filemtime($path) : '1');
    }
}
