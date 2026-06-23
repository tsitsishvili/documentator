<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator;

use Illuminate\Contracts\Routing\Registrar as Router;
use Illuminate\Support\ServiceProvider;
use Tsitsishvili\Documentator\Commands\ExportCommand;
use Tsitsishvili\Documentator\Commands\GenerateCommand;
use Tsitsishvili\Documentator\Commands\PostmanCommand;
use Tsitsishvili\Documentator\Extraction\ExtractorPipeline;
use Tsitsishvili\Documentator\Extraction\RouteCollector;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractAttributes;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractFormRequestRules;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractResponses;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractRouteMetadata;
use Tsitsishvili\Documentator\Http\Controllers\AssetController;
use Tsitsishvili\Documentator\Http\Controllers\DocsController;
use Tsitsishvili\Documentator\Http\Controllers\OpenApiController;
use Tsitsishvili\Documentator\Http\Middleware\Authorize;
use Tsitsishvili\Documentator\Http\Middleware\EnsureDocsEnabled;
use Tsitsishvili\Documentator\OpenApi\OpenApiGenerator;

final class DocumentatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/documentator.php', 'documentator');

        $this->app->singleton(RouteCollector::class, function ($app) {
            return new RouteCollector(
                $app['router'],
                (array) config('documentator.routes.match', ['api/*']),
                (array) config('documentator.routes.exclude', []),
            );
        });

        // Strategies run in declaration order. Inference fills the gaps first;
        // ExtractAttributes runs LAST so explicit attributes always win.
        $this->app->singleton(ExtractorPipeline::class, function ($app) {
            return new ExtractorPipeline([
                $app->make(ExtractRouteMetadata::class),
                $app->make(ExtractFormRequestRules::class),
                $app->make(ExtractResponses::class),
                $app->make(ExtractAttributes::class),
            ]);
        });

        $this->app->singleton(Documentator::class, function ($app) {
            return new Documentator(
                $app->make(RouteCollector::class),
                $app->make(ExtractorPipeline::class),
                $app->make(OpenApiGenerator::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'documentator');
        $this->registerRoutes($this->app->make('router'));

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateCommand::class, ExportCommand::class, PostmanCommand::class]);

            $this->publishes([
                __DIR__.'/../config/documentator.php' => $this->app->configPath('documentator.php'),
            ], 'documentator-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/documentator'),
            ], 'documentator-views');
        }
    }

    private function registerRoutes(Router $router): void
    {
        $router->group([
            'prefix' => config('documentator.route.prefix', 'docs'),
            'domain' => config('documentator.route.domain'),
            // EnsureDocsEnabled gates existence (cheap, no user needed); the host
            // app's middleware (e.g. "web") resolves the session/user; Authorize
            // runs last so its callback can read $request->user().
            'middleware' => array_merge(
                [EnsureDocsEnabled::class],
                (array) config('documentator.route.middleware', ['web']),
                [Authorize::class],
            ),
        ], function (Router $router) {
            $router->get('/', [DocsController::class, 'index'])->name('documentator.ui');
            $router->get('/openapi.json', [OpenApiController::class, 'show'])->name('documentator.openapi');
            $router->get('/assets/{asset}', [AssetController::class, 'show'])
                ->where('asset', 'app\.(css|js)')
                ->name('documentator.asset');
        });
    }
}
