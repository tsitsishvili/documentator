<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Extraction;

use Illuminate\Routing\Route;
use ReflectionMethod;
use Tsitsishvili\Documentator\Data\EndpointData;

/**
 * One step of the extraction pipeline. Each strategy receives the accumulated
 * EndpointData and returns it enriched. The controller's ReflectionMethod is
 * provided for controller routes; strategies can inspect the Route itself for
 * closure actions.
 */
interface ExtractionStrategy
{
    public function __invoke(EndpointData $endpoint, Route $route, ?ReflectionMethod $method): EndpointData;
}
