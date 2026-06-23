<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API metadata
    |--------------------------------------------------------------------------
    |
    | These values populate the `info` block of the generated OpenAPI document
    | and the title of the interactive docs page.
    |
    */

    'title' => env('DOCUMENTATOR_TITLE', config('app.name').' API'),
    'version' => env('DOCUMENTATOR_VERSION', '1.0.0'),
    'description' => env('DOCUMENTATOR_DESCRIPTION', null),

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    |
    | Whether the docs routes are reachable. Leave null to open them everywhere
    | except production; set true/false to force it. Combine with route
    | middleware below to put the docs behind auth. To restrict *who* may view
    | them, register a gate with Documentator::auth() from a service provider:
    |
    |     Documentator::auth(fn ($request) => $request->user()?->is_admin);
    |
    */

    'enabled' => env('DOCUMENTATOR_ENABLED', null),

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | The base URLs third parties can send "try it out" requests to. The first
    | entry is selected by default in the UI.
    |
    */

    'servers' => [
        ['url' => env('APP_URL', 'http://localhost'), 'description' => 'Default'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Docs UI route
    |--------------------------------------------------------------------------
    |
    | Where the interactive Scalar UI and the raw OpenAPI document are served.
    | Lock these down with middleware in non-public APIs.
    |
    */

    'route' => [
        'prefix' => env('DOCUMENTATOR_PREFIX', 'docs'),
        'domain' => env('DOCUMENTATOR_DOMAIN', null),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    |
    | "documentator" renders the built-in Aurora explorer, served from
    | this package with no external assets. "scalar" embeds the Scalar bundle
    | instead (`assets` is the pinned, overridable Scalar URL — self-host it to
    | apply Subresource Integrity / a Content-Security-Policy).
    |
    */

    'ui' => [
        'driver' => env('DOCUMENTATOR_UI', 'documentator'),
        'assets' => env('DOCUMENTATOR_UI_ASSETS', 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.25.0/dist/browser/standalone.min.js'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Which routes to document
    |--------------------------------------------------------------------------
    |
    | `match` includes routes whose URI matches any of these patterns (Str::is
    | wildcards). `exclude` removes routes whose URI or name matches. Routes
    | marked #[Hidden] are always excluded.
    |
    */

    'routes' => [
        'match' => ['api/*'],
        'exclude' => [
            'telescope*',
            'horizon*',
            '_debugbar*',
            'sanctum/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model resolution
    |--------------------------------------------------------------------------
    |
    | When inferring response types, an API Resource's wrapped model is resolved
    | by convention (e.g. UserResource -> {namespace}\User) so its $casts can
    | type the fields. Override per resource with #[UsesModel(Model::class)].
    |
    */

    'models_namespace' => 'App\\Models',

    /*
    |--------------------------------------------------------------------------
    | Authentication schemes
    |--------------------------------------------------------------------------
    |
    | Declared as OpenAPI `securitySchemes`. The key is referenced by the
    | #[Authenticated] attribute (defaults to "default"). The UI uses these to
    | render the authorize / token input for "try it out".
    |
    */

    'security' => [
        'default' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'description' => 'Pass an API token as a Bearer header.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Spec caching
    |--------------------------------------------------------------------------
    |
    | When enabled the generated OpenAPI document is read from a cached file
    | (written by `php artisan documentator:generate`) instead of being built
    | on every request. Recommended in production.
    |
    */

    'cache' => [
        'enabled' => env('DOCUMENTATOR_CACHE', false),
        'path' => storage_path('app/documentator/openapi.json'),
    ],

];
