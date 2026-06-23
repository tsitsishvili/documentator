<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tsitsishvili\Documentator\Documentator;

/**
 * Authorization gate for the docs routes. Open by default; restrict access by
 * registering a callback with Documentator::auth() from a service provider.
 * Runs after the route's auth middleware so $request->user() is resolved.
 */
final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Documentator::check($request), 403);

        return $next($request);
    }
}
