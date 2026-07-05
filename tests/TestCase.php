<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tsitsishvili\Documentator\DocumentatorServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DocumentatorServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The docs routes run through the "web" middleware group, which needs a
        // key to encrypt cookies/sessions.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('documentator.enabled', true);
    }
}
