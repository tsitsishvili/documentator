<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction\Support;

use Closure;
use Illuminate\Routing\Route;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Throwable;

final class RouteActionReflection
{
    public static function for(Route $route, ?ReflectionMethod $method): ?ReflectionFunctionAbstract
    {
        if ($method !== null) {
            return $method;
        }

        $uses = $route->getAction('uses');

        if (! $uses instanceof Closure) {
            return null;
        }

        try {
            return new ReflectionFunction($uses);
        } catch (Throwable) {
            return null;
        }
    }
}
