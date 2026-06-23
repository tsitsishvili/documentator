<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Serves the docs UI: the built-in Aurora explorer by default, or the
 * Scalar embed when config('documentator.ui.driver') is "scalar".
 */
final class DocsController
{
    public function index(): View
    {
        if (config('documentator.ui.driver') === 'scalar') {
            return view('documentator::scalar', [
                'title' => config('documentator.title'),
                'specUrl' => route('documentator.openapi'),
                'assets' => config('documentator.ui.assets'),
            ]);
        }

        return view('documentator::docs', [
            'title' => config('documentator.title'),
            'specUrl' => route('documentator.openapi'),
            'cssUrl' => route('documentator.asset', 'app.css'),
            'jsUrl' => route('documentator.asset', 'app.js'),
        ]);
    }
}
