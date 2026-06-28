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
        'auth_storage' => env('DOCUMENTATOR_AUTH_STORAGE', 'local'), // local, session, memory
    ],

    /*
    |--------------------------------------------------------------------------
    | Which routes to document
    |--------------------------------------------------------------------------
    |
    | `match` includes routes whose URI matches any of these patterns (Str::is
    | wildcards). `exclude` removes routes whose URI or name matches.
    | `exclude_middleware` removes routes using matching middleware aliases or
    | class names. Routes marked #[Hidden] are always excluded.
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
        'exclude_middleware' => [
            // 'internal',
            // 'can:admin-only',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grouping
    |--------------------------------------------------------------------------
    |
    | Explicit #[Group] attributes always win. Without an attribute, "auto"
    | groups controller actions by controller name and groups controller-less
    | routes from the URI. Set "path" to group every route from its URI instead,
    | or "controller" to keep the old controller-only behavior. `sections`
    | split the built-in UI by route surface (for example API vs App) while
    | keeping useful endpoint groups inside each section. Path grouping skips
    | common prefixes and parameter-only segments by default, so
    | /api/{locale}/orders lands under "Orders" instead of "Locale".
    |
    */

    'grouping' => [
        'source' => env('DOCUMENTATOR_GROUPING', 'auto'), // auto, controller, path
        'path_depth' => 1,
        'ignore_path_prefixes' => ['api'],
        'ignore_path_parameters' => true,
        'sections' => [
            // 'api' => 'API',
            // 'app' => 'App',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global path parameters
    |--------------------------------------------------------------------------
    |
    | Describe route-wide placeholders once, such as a locale/tenant segment
    | present on many routes. These values are applied to every matching path
    | parameter before attributes run, so #[PathParam] can still override them.
    | When path grouping is enabled, entries with "grouping" => false are skipped
    | even if grouping.ignore_path_parameters is false.
    |
    | Example:
    | 'pathlang' => [
    |     'description' => 'Language code used by localized routes.',
    |     'schema' => ['type' => 'string', 'enum' => ['ka', 'en', 'ru']],
    |     'example' => 'ka',
    |     'grouping' => false,
    | ],
    |
    */

    'global_path_parameters' => [
        // 'pathlang' => ['description' => 'Language code.', 'example' => 'ka'],
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
    | Inferred error responses
    |--------------------------------------------------------------------------
    |
    | Adds the conventional error responses an endpoint can return based on its
    | shape, so you don't have to declare each one: 422 when a FormRequest
    | validates the body, 401 when the endpoint requires authentication, 403
    | when a FormRequest overrides authorize(), and 404 when the route binds a
    | model. Explicit #[Response] attributes always override these. Set false to
    | document only the responses you declare.
    |
    */

    'error_responses' => env('DOCUMENTATOR_ERROR_RESPONSES', true),

    /*
    |--------------------------------------------------------------------------
    | Inferred success status codes
    |--------------------------------------------------------------------------
    |
    | Picks a conventional success status from the HTTP verb instead of always
    | documenting 200: POST -> 201 Created, DELETE -> 204 No Content. An explicit
    | #[Response] attribute always overrides this. Set false to keep every
    | inferred success response at 200.
    |
    */

    'infer_status_codes' => env('DOCUMENTATOR_STATUS_CODES', true),

    /*
    |--------------------------------------------------------------------------
    | Generated examples
    |--------------------------------------------------------------------------
    |
    | Seeds a representative example for every request body and parameter that
    | doesn't declare one, derived from its type/format/enum (e.g. an email field
    | -> user@example.com). This prefills the "Try it" playground. An explicit
    | example always wins. Set false to emit only the examples you declare.
    |
    */

    'generate_examples' => env('DOCUMENTATOR_EXAMPLES', true),

    /*
    |--------------------------------------------------------------------------
    | Extension hooks
    |--------------------------------------------------------------------------
    |
    | Custom extraction strategies are resolved from the container and inserted
    | before ExtractAttributes, so explicit attributes can still override them.
    | OpenAPI transformers receive the generated spec array and may return a
    | modified array; use them for organization-specific naming or metadata.
    |
    */

    'extensions' => [
        'strategies' => [
            // App\Documentator\ExtractTenantMetadata::class,
        ],
        'openapi_transformers' => [
            // App\Documentator\AddInternalMetadata::class,
        ],
    ],

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
    | Authentication middleware aliases
    |--------------------------------------------------------------------------
    |
    | These patterns tell the extractor which route middleware imply an OpenAPI
    | security requirement. The value is the security scheme key. Use null for
    | guard-aware auth middleware: auth:sanctum maps to a "sanctum" scheme when
    | one exists, otherwise it falls back to "default".
    |
    */

    'auth_middleware' => [
        'auth' => 'default',
        'auth:*' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Global authentication
    |--------------------------------------------------------------------------
    |
    | Require a security scheme across the whole API instead of marking each
    | endpoint. Emitted as the document's top-level `security`, which applies to
    | every operation. Set true to require the "default" scheme, or name another
    | scheme from `security` above. Endpoints that aren't authenticated (no
    | `auth` middleware / #[Authenticated]) opt out automatically and stay
    | public. Leave false to declare auth per-endpoint.
    |
    */

    'authenticate' => env('DOCUMENTATOR_AUTHENTICATE', false),

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
