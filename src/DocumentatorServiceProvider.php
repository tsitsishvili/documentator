<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator;

use Illuminate\Contracts\Routing\Registrar as Router;
use Illuminate\Support\ServiceProvider;
use Tsitsishvili\Documentator\Commands\CheckCommand;
use Tsitsishvili\Documentator\Commands\ExplainCommand;
use Tsitsishvili\Documentator\Commands\ExportCommand;
use Tsitsishvili\Documentator\Commands\GenerateCommand;
use Tsitsishvili\Documentator\Commands\PostmanCommand;
use Tsitsishvili\Documentator\Extraction\ExtractorPipeline;
use Tsitsishvili\Documentator\Extraction\RouteCollector;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractAttributes;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractDataObjects;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractErrorResponses;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractFormRequestRules;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractInlineResponses;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractInlineValidationRules;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractLaravelActions;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractResponses;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractRouteMetadata;
use Tsitsishvili\Documentator\Extraction\Strategies\ExtractSpatieQueryBuilder;
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
                (array) config('documentator.routes.exclude_middleware', []),
            );
        });

        // Strategies run in declaration order. Inference fills the gaps first;
        // ExtractAttributes runs LAST so explicit attributes always win.
        $this->app->singleton(ExtractorPipeline::class, function ($app) {
            return new ExtractorPipeline([
                $app->make(ExtractRouteMetadata::class),
                $app->make(ExtractFormRequestRules::class),
                $app->make(ExtractLaravelActions::class),
                $app->make(ExtractInlineValidationRules::class),
                $app->make(ExtractDataObjects::class),
                $app->make(ExtractSpatieQueryBuilder::class),
                $app->make(ExtractResponses::class),
                $app->make(ExtractInlineResponses::class),
                $app->make(ExtractErrorResponses::class),
                ...array_map(
                    fn (string $strategy) => $app->make($strategy),
                    (array) config('documentator.extensions.strategies', []),
                ),
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
            $this->commands([GenerateCommand::class, ExportCommand::class, PostmanCommand::class, CheckCommand::class, ExplainCommand::class]);

            $this->publishes([
                __DIR__.'/../config/documentator.php' => $this->app->configPath('documentator.php'),
            ], 'documentator-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/documentator'),
            ], 'documentator-views');

            // AI agent guidance for tools that don't auto-discover it via Laravel
            // Boost (Boost reads resources/boost/** straight from the package).
            $this->publishes([
                __DIR__.'/../resources/boost/skills/documentator-api-docs' => $this->app->basePath('.claude/skills/documentator-api-docs'),
                __DIR__.'/../resources/ai/guidelines/documentator.md' => $this->app->basePath('.ai/guidelines/documentator.md'),
                __DIR__.'/../resources/ai/cursor/documentator.mdc' => $this->app->basePath('.cursor/rules/documentator.mdc'),
                __DIR__.'/../resources/ai/gemini/documentator.md' => $this->app->basePath('GEMINI.md'),
                __DIR__.'/../resources/ai/codex/documentator.md' => $this->app->basePath('AGENTS.md'),
            ], 'documentator-ai');
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
            $router->get('/{section}/openapi.json', [OpenApiController::class, 'show'])
                ->where('section', '[A-Za-z0-9_-]+')
                ->name('documentator.openapi.section');
            $router->get('/assets/{asset}', [AssetController::class, 'show'])
                ->where('asset', '(app\.css|app\.js|core\.js|snippets\.js)')
                ->name('documentator.asset');
            $router->get('/{section}', [DocsController::class, 'index'])
                ->where('section', '[A-Za-z0-9_-]+')
                ->name('documentator.ui.section');
        });
    }
}
