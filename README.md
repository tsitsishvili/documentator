# Documentator

Interactive API documentation for Laravel that mostly writes itself.

Documentator scans your application's routes, FormRequests, API Resources and
controller return types to infer documentation automatically, lets you refine
anything with PHP attributes, and serves an interactive UI — backed by a standard
**OpenAPI 3.1** document — so third parties can browse your endpoints, read
descriptions, and try requests live.

- **Zero-config by default** — point it at `api/*` and you get docs.
- **Attribute overrides** — enrich or correct any inferred detail.
- **Built-in explorer** — a dark "Aurora" UI (no external assets) with a request
  playground, auth, and live cURL. Or switch to [Scalar](https://scalar.com).
- **Standard output** — a plain OpenAPI 3.1 document other tools can consume.

Requires PHP 8.2+ and Laravel 12 or 13.

## Installation

```bash
composer require tsitsishvili/documentator
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=documentator-config
```

Visit `/docs` to see the interactive UI. The raw spec is at `/docs/openapi.json`.

## How inference works

For every route matching `config('documentator.routes.match')`, an ordered
pipeline enriches the endpoint:

| Source | Produces |
| --- | --- |
| Route definition | verbs, URI, path params, name, auth guess from `auth` middleware |
| FormRequest `rules()` | body parameters with types, required, **enums** (`in:`), **formats** (email/uuid/date), bounds (`min`/`max`), nullability, **nested** rules (`items.*.id`), and **file uploads** → multipart |
| Controller return type | a success response with a body schema read from the Resource's `toArray()` — nested resources followed, types from the **model's `$casts`**, `whenLoaded` fields optional, `ResourceCollection` wrapped in the **paginator envelope** |
| PHP attributes | overrides for everything above (runs last) |

```php
// Inferred with no annotations: path param {order}, body params from
// StoreOrderRequest::rules(), a 200 response from OrderResource.
public function store(StoreOrderRequest $request): OrderResource
{
    return new OrderResource(Order::create($request->validated()));
}
```

## Overriding with attributes

Attributes always win over inference. Mix and match as needed:

```php
use Tsitsishvili\Documentator\Attributes\{Summary, Description, Group, BodyParam, Response, Authenticated};

#[Group('Orders')]
#[Summary('Create an order')]
#[Description('Creates an order for the authenticated customer.')]
#[Authenticated]
#[BodyParam('coupon', 'string', required: false, description: 'Optional promo code')]
#[Response(201, resource: OrderResource::class, description: 'Order created')]
#[Response(422, description: 'Validation failed')]
public function store(StoreOrderRequest $request): OrderResource
{
    // ...
}
```

Available attributes: `Summary`, `Description`, `Group`, `Authenticated`,
`Hidden`, `Deprecated`, `BodyParam`, `QueryParam`, `PathParam`, `Response`.
`Group`, `Authenticated`, `Hidden` and `Deprecated` may also be placed on the
controller class to set a default for all its methods (`#[Deprecated]` also
honours PHP 8.4's native `#[\Deprecated]`). `#[Response(resource: X, paginated: true)]` (or
`collection: true`) wraps a resource in the paginator / `{ data: [...] }` envelope.

Put `#[UsesModel(Order::class)]` on a Resource to tell the extractor which
Eloquent model it wraps (otherwise the model is resolved by naming convention,
configurable via `models_namespace`), so field types come from the model's casts.

## Authentication

Auth schemes are declared in `config('documentator.security')` as OpenAPI
`securitySchemes`. Endpoints behind `auth` middleware are marked authenticated
automatically; use `#[Authenticated('scheme-key')]` to be explicit or pick a
non-default scheme. The UI renders the matching authorize / token input.

## Trying requests

The built-in explorer can call your API live. It remembers the auth token and
selected server across endpoints, deep-links each endpoint (`#get-api-orders`)
for sharing and reload, renders Markdown in descriptions, and shows a copyable
cURL. Shortcuts: `/` focuses search, `Cmd/Ctrl+Enter` sends, `Esc` closes panels.
Cross-origin "try it" calls require the API to allow CORS from the docs origin.

## Production

The docs are open everywhere except production by default; in production set
`DOCUMENTATOR_ENABLED=true` (and/or add auth via `route.middleware`) to expose
them. To restrict *who* may view the docs, register an authorization gate from
a service provider's `boot()` — it runs after the route middleware, so the
authenticated user is available:

```php
use Tsitsishvili\Documentator\Documentator;

Documentator::auth(fn ($request) => $request->user()?->is_admin);
```

Returning `false` aborts with a 403. Building the document scans routes per
request, so pre-build it:

```bash
php artisan documentator:generate                  # warm the cache (set DOCUMENTATOR_CACHE=true)
php artisan documentator:export openapi.json        # write the OpenAPI spec for CI / tooling
php artisan documentator:postman collection.json    # export a Postman v2.1 collection
```

## Configuration

Key options in `config/documentator.php`:

- `enabled` — docs access; `null` = open except in production, or force `true`/`false`. Restrict *who* may view with `Documentator::auth()`.
- `routes.match` / `routes.exclude` — which routes are documented.
- `route.prefix` / `route.middleware` / `route.domain` — where the UI is served. Lock it down for private APIs.
- `title` / `version` / `description` / `servers` — OpenAPI `info` and server list.
- `security` — auth schemes.
- `models_namespace` — where Resources' wrapped models live (for cast-based typing).
- `ui.driver` — `documentator` (built-in explorer, default) or `scalar`.
- `ui.assets` — Scalar bundle URL when `ui.driver = scalar` (pinned; self-host for SRI/CSP).
- `cache` — pre-generated spec file.

## Development

```bash
composer install
composer test      # Pest + Orchestra Testbench
composer lint      # Laravel Pint
```
