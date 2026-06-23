<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the docs routes. With config('documentator.enabled') unset (null),
 * the docs are open everywhere except production; set it to an explicit
 * true/false to force the behaviour.
 */
final class EnsureDocsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = config('documentator.enabled');

        $allowed = $enabled === null ? ! app()->isProduction() : (bool) $enabled;

        abort_unless($allowed, 404);

        return $next($request);
    }
}
