---
name: documentator-api-docs
description: Make a Laravel API document itself with tsitsishvili/documentator — write controllers, FormRequests, API Resources and return types so accurate OpenAPI 3.1 docs are inferred, and override with the package's PHP attributes. Use when adding, changing, or documenting API endpoints in a Laravel app that has tsitsishvili/documentator installed.
---

# Documentator: make your Laravel API document itself

`tsitsishvili/documentator` generates interactive **OpenAPI 3.1** docs by
*inferring* them from your existing Laravel code — routes, FormRequests, inline
validation, API Resources, return types and docblocks — and lets you refine the
result with PHP attributes. There is no separate spec file to maintain.

**The golden rule: inference first, attributes last.** Write idiomatic, typed
Laravel and most of the documentation appears on its own. Reach for an attribute
only to add or correct something inference cannot see. Attributes always win, so
never fight them against each other — pick one source per fact.

## When to use this skill

Use it whenever you add or edit an endpoint under the documented route prefix
(default `api/*`) and want its docs to be correct: choosing between a FormRequest
and an attribute, wondering why a parameter or response is missing, grouping
endpoints, marking auth, or wiring the Artisan checks into CI.

## Enabling the docs (disabled by default)

The docs routes are **off by default** for safety. In the consuming app:

```dotenv
DOCUMENTATOR_ENABLED=true
```

- Interactive UI: `/docs` — raw spec: `/docs/openapi.json`.
- Lock down non-public APIs with route middleware (`config('documentator.route.middleware')`)
  and/or a gate: `Documentator::auth(fn ($request) => $request->user()?->is_admin)`.
- Only routes whose URI matches `config('documentator.routes.match')` (default
  `['api/*']`) are documented.

## Write code the inferrer can read

Prefer these idioms — each row is something documentator extracts with **no
annotation**. This is the primary way to get good docs.

| To document…                     | Write this in the app                                                                                   |
| -------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Summary + description            | A docblock on the controller method — **first line = summary**, the rest = description.                 |
| Path params (typed)              | Route-model binding or a numeric constraint → the param is typed `integer` automatically.               |
| Request body                     | Type-hint a `FormRequest`; its `rules()` become body params (types, required, enums, formats, nesting). |
| Body on GET/HEAD                 | Same `FormRequest` — on GET/HEAD the rules become **query parameters** instead of a body.               |
| Body without a FormRequest       | Inline `$request->validate([...])`, `request()->validate([...])`, or `Validator::make(..., [...])`.     |
| Individual query params          | Request accessors: `$request->integer('page')`, `$request->boolean('active')`, `$request->query('q')`.  |
| Success response                 | Give the method a **return type**: an API `Resource`, `ResourceCollection`, model, or `Data` object.    |
| Paginated response + page params | `return ResourceClass::collection($query->paginate())` — envelope + `page`/`per_page` are inferred.      |
| Literal JSON response            | `return response()->json([...], 202)` — shape and status are read from the literal.                     |
| Filters / sorts / includes       | `spatie/laravel-query-builder`: literal `allowedFilters()`, `allowedSorts()`, `allowedIncludes()`.      |

A fully self-documenting endpoint needs no attributes at all:

```php
/**
 * Create an order.
 *
 * Charges the customer and returns the created order.
 */
public function store(StoreOrderRequest $request): OrderResource
{
    return new OrderResource(Order::create($request->validated()));
}
// Inferred: {order} path param, body params from StoreOrderRequest::rules(),
// 201 response from OrderResource, plus 401/403/404/422 where the shape implies them.
```

Notes that change the output:

- **Status codes** follow the verb: `POST → 201`, `DELETE → 204`, otherwise `200`.
- **Error responses** (401/403/404/422) are added automatically from the endpoint's
  shape (auth middleware, `authorize()`, model binding, validation).
- **Validation rules** are parsed richly: `in:`/`Rule::enum`/`Rule::in` → enum,
  `email`/`uuid`/`date` → format, `min`/`max` → bounds, `regex:` → pattern,
  `confirmed` → a `_confirmation` field, `nested.*.field` → nested schema,
  `file`/`image` → multipart upload.
- Local PHPDoc tags refine inline params: `@var`, `@example`, `@default`,
  `@query`, `@body`, `@ignoreParam`.
- **Optional integrations** are auto-detected and are a no-op when the package is
  absent: `spatie/laravel-data` (request/response Data objects),
  `spatie/laravel-query-builder`, `lorisleiva/laravel-actions`.

## Overriding with attributes

Use attributes only to fill gaps inference can't reach (a hand-written response
example, a manual query param, grouping, auth). They run **last** and win.

```php
use Tsitsishvili\Documentator\Attributes\{
    Summary, Description, Group, TagDescription, OperationId, Authenticated, Server,
    PathParam, QueryParam, HeaderParam, CookieParam, BodyParam, RequestMediaType,
    Response, ResponseHeader, SchemaName, UsesModel, Hidden, Deprecated,
};
```

| Attribute (constructor)                                                        | Use it to…                                                       |
| ------------------------------------------------------------------------------ | ---------------------------------------------------------------- |
| `#[Summary('...')]` / `#[Description('...')]`                                   | Set text when there is no docblock, or override it.              |
| `#[Group('Orders', version: null)]`                                            | Force the tag/section an endpoint belongs to.                    |
| `#[TagDescription('...')]`                                                     | Add a description to the OpenAPI tag for this group.             |
| `#[OperationId('createOrder')]`                                                | Set a stable `operationId`.                                      |
| `#[Authenticated(scheme: 'default')]`                                          | Mark an endpoint as requiring a security scheme.                 |
| `#[Server('https://tenant.example.com', description: '...')]`                  | Add an endpoint-specific server.                                 |
| `#[PathParam('id', type: 'integer', description: '...', example: 1)]`          | Describe/type a path param inference missed.                     |
| `#[QueryParam('q', required: false, description: '...')]`                      | Add a query param not visible in code.                           |
| `#[BodyParam('note', type: 'string', required: false)]`                        | Add/adjust a body field.                                         |
| `#[HeaderParam(...)]` / `#[CookieParam(...)]`                                  | Document request headers / cookies.                              |
| `#[RequestMediaType('multipart/form-data')]`                                   | Force the request content type.                                  |
| `#[Response(status: 201, resource: OrderResource::class, description: '...')]` | Declare a response inference can't derive; `collection`/`paginated` flags available. |
| `#[ResponseHeader('X-RateLimit-Remaining', ...)]`                              | Document a response header.                                      |
| `#[SchemaName('Order')]` / `#[UsesModel(Order::class)]`                        | Name a reusable component schema / point a Resource at its model. |
| `#[Hidden]`                                                                    | Exclude the route from the docs entirely.                        |
| `#[Deprecated]`                                                                | Mark the operation deprecated.                                   |

`Response`, `Server`, `BodyParam`/`QueryParam`/etc. and `ResponseHeader` are
repeatable — stack them. Attributes go on the controller **method** (a few also
work on the class or a Data/Action class).

## Grouping, auth, sections (config)

- **Grouping** (`config('documentator.grouping')`): `auto` groups by controller
  (or URI for controller-less routes); `path` groups by URI; `controller` keeps
  controller-only. `#[Group]` always overrides.
- **Sections** (`grouping.sections`): split the UI/spec by route surface, e.g.
  `['api' => 'API', 'app' => 'App']`, each served at `/docs/{section}` and
  `/docs/{section}/openapi.json`.
- **Auth**: map middleware → scheme in `config('documentator.auth_middleware')`
  (e.g. `auth` and `auth:*`), declare schemes under `config('documentator.security')`,
  or require auth globally with `DOCUMENTATOR_AUTHENTICATE=true`.
- **Global path params** (`global_path_parameters`): describe a repeated
  segment like `{locale}`/`{tenant}` once instead of per route.

## Artisan commands (use `check` in CI)

```bash
php artisan documentator:check                         # audit docs quality + validate the OpenAPI shape
php artisan documentator:check --against=openapi.json  # fail if the generated spec drifted from a committed spec
php artisan documentator:generate                      # build & cache the spec (pair with DOCUMENTATOR_CACHE=true in production)
php artisan documentator:export path/to/openapi.json   # write the spec to a file
php artisan documentator:postman                       # export a Postman collection
```

Run `documentator:check` after changing endpoints and in CI — it flags
undocumented params and missing descriptions. Add `--against=openapi.json`
when CI should fail on drift from a committed spec.

## Gotchas that trip agents

- **Closure routes skip reflection-based inference.** Only `[Controller::class, 'method']`
  routes get FormRequest/return-type/attribute extraction. Prefer controller
  actions for anything that should be documented richly.
- **GET/HEAD FormRequest rules become query parameters**, not a request body.
- **One source per fact.** Inference fills gaps non-destructively; an attribute
  overrides. Don't set the same thing two ways.
- **Docs are disabled by default** — nothing appears at `/docs` until
  `DOCUMENTATOR_ENABLED=true`.
- **Generation never throws:** an endpoint the package can't analyse is silently
  skipped rather than breaking the document. If a route is missing from the docs,
  it usually means inference couldn't read it — add the relevant type hint,
  return type, or attribute.

## Quick checklist for a new/edited endpoint

1. Controller action (not a closure), under the documented prefix.
2. Docblock: first line summary, rest description.
3. Request: a type-hinted `FormRequest` (or inline `validate()` / `Data` object).
4. Response: a concrete **return type** (Resource / model / `Data` / paginator).
5. Only then add attributes for anything still missing or wrong.
6. `php artisan documentator:check` is clean.
