<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the docs routes. Docs are disabled unless the host application
 * explicitly enables them with config('documentator.enabled').
 */
final class EnsureDocsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = config('documentator.enabled');

        $allowed = (bool) $enabled;

        abort_unless($allowed, 404);

        return $next($request);
    }
}
