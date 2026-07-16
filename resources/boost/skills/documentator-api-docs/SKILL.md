---
name: documentator-api-docs
description: Build, change, diagnose, or verify Laravel API endpoints documented by tsitsishvili/documentator. Use for routes, controllers, closures, FormRequests, inline validation, API Resources, Eloquent models, Spatie Data, Laravel Actions, HTTP QUERY operations, Documentator attributes, auth/grouping configuration, missing or inaccurate generated operations, Artisan documentation checks, OpenAPI contract drift, and Postman exports.
---

# Build self-documenting Laravel endpoints

Generate accurate **OpenAPI 3.2** through application code. Prefer inference
from idiomatic Laravel; add Documentator attributes only for facts the code
cannot express or inference cannot see. Treat an attribute as an intentional
last-write-wins override.

## Follow the endpoint workflow

### 1. Inspect the complete endpoint

Read these sources before editing:

1. `config/documentator.php`, especially route matching/exclusions, auth,
   grouping, status inference, error responses, examples, and extensions.
2. The route definition and middleware.
3. The controller method, invokable action, or closure and its PHPDoc.
4. The FormRequest, inline validation, request accessors, or Data input.
5. The Resource, Data object, model, return type, and readable return expressions.
6. Existing feature tests and any committed OpenAPI contract.

Confirm the route URI matches `documentator.routes.match` and is not matched by
`routes.exclude`, `routes.exclude_middleware`, or `#[Hidden]`.

### 2. Assign one source to each fact

Decide where each fact belongs:

| Fact | Preferred source |
| --- | --- |
| Method and URI | Laravel route |
| Summary and description | Method/closure docblock |
| Path input | Route placeholder, constraint, or model binding |
| URI query input | Request accessor, GET/HEAD validation, Spatie Query Builder, or `#[QueryParam]` |
| Request content | FormRequest, inline validation, Data input, or `#[BodyParam]` |
| Success schema | Return type/expression, Resource/Data/model, or `#[Response]` |
| Auth | Middleware/config, global auth, or `#[Authenticated]` |
| Group/version | Config/controller inference or `#[Group]` |
| Visibility/lifecycle | `#[Hidden]` / `#[Deprecated]` |

Avoid describing the same fact twice unless the attribute deliberately corrects
an inferred value.

### 3. Implement with inference-first patterns

Prefer controller routes when class metadata, grouping, or inherited attributes
matter. Use typed closures when controller metadata is unnecessary.

Write method PHPDoc as prose:

```php
/**
 * Create an order.
 *
 * Charge the customer and return the created order.
 */
public function store(StoreOrderRequest $request): OrderResource
{
    return new OrderResource(Order::create($request->validated()));
}
```

Let Documentator infer body fields and validation errors from
`StoreOrderRequest::rules()`, the success schema from `OrderResource`, and the
conventional `201` status from `POST`.

### 4. Trace and verify

Run the operation-specific trace before the broad audit:

```bash
php artisan documentator:explain POST /api/orders
php artisan documentator:check
```

When the repository commits an OpenAPI contract, also run:

```bash
php artisan documentator:check --against=openapi.json
php artisan documentator:check --against=openapi.json --fail-on=breaking
```

Inspect `/docs/openapi.json` or an exported operation when exact parameter
placement, schemas, media types, or status codes matter.

## Place request input by method

### GET and HEAD

Treat FormRequest, Data input, and inline validation as URI query parameters.
Do not force a request body onto `GET` or `HEAD`.

### HTTP QUERY

Register `QUERY` through Laravel's custom-method route API:

```php
Route::match(['QUERY'], 'api/orders/search', [OrderSearchController::class, 'search']);
```

Keep structured criteria in request content:

```php
final class SearchOrdersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'term' => ['required', 'string'],
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'limit' => ['sometimes', 'integer', 'max:100'],
        ];
    }
}

/** Search orders using structured criteria. */
public function search(SearchOrdersRequest $request): OrderCollection
{
    return new OrderCollection(Order::search($request->validated()));
}
```

Expect an OpenAPI 3.2 `query` Path Item operation with a `requestBody`. Keep URI
query parameters separate: infer those from `$request->query('cursor')` or use
`#[QueryParam]` only when the code does not expose them.

Treat `QUERY` as safe and idempotent at the API-design level. Do not replace a
mutating `POST` merely to obtain body support.

### Other body-bearing methods

Treat FormRequest/Data/inline-validation fields as request content. Allow
`file`/`image` rules to select multipart input, or use `#[RequestMediaType]` for
an explicit content type inference cannot determine.

## Use supported inference signals

### Requests

- Parse `FormRequest::rules()` and literal inline validation from
  `$request->validate`, `request()->validate`, and `Validator::make`.
- Infer individual inputs from request accessors such as `integer`, `boolean`,
  `string`, `query`, `post`, `file`, and `enum`.
- Preserve enums, formats, bounds, regex patterns, confirmed fields, nested
  wildcard fields, nullable unions, and uploads where readable.
- Apply local inline-parameter PHPDoc refinements: `@var`, `@example`,
  `@default`, `@query`, `@body`, and `@ignoreParam`.
- Detect supported Spatie Data input mapping and `Optional`/`Lazy` requiredness.

### Responses

- Prefer concrete Resource, ResourceCollection, model, Data, paginator, or
  array return types and readable return expressions.
- Infer literal JSON/array responses and common Laravel response helpers.
- Preserve distinct same-status branches with `oneOf` when their shapes differ.
- Mark unconditional Resource fields required and conditional `when*`/
  `mergeWhen` fields optional.
- Expect `POST → 201`, `DELETE → 204`, otherwise `200`, unless an explicit
  response/status overrides the convention.

### Errors and integrations

- Infer possible errors from auth, validation, model binding, FormRequest
  authorization, Gate/controller authorization, literal `abort*`, and recognized
  Laravel/Symfony HTTP exceptions.
- Use supported optional integrations only when installed: Spatie Data, Spatie
  Query Builder, Laravel Actions, and JSON:API pagination helpers.

## Add attributes only for gaps

Import attributes from `Tsitsishvili\Documentator\Attributes`.

| Attribute | Use |
| --- | --- |
| `Summary`, `Description` | Override missing or inaccurate prose |
| `Group`, `TagDescription`, `OperationId` | Control organization and identity |
| `PathParam`, `QueryParam`, `HeaderParam`, `CookieParam` | Add invisible request parameters |
| `BodyParam`, `RequestMediaType` | Add invisible request content or media type |
| `Response`, `ResponseHeader` | Add/override response contracts |
| `Authenticated` | Require a configured security scheme |
| `Server` | Add an operation-specific server |
| `SchemaName`, `UsesModel` | Control reusable schema naming/model association |
| `Hidden`, `Deprecated` | Control visibility and lifecycle |

Use repeatable parameter, response, server, and response-header attributes as
needed. Place attributes on the method/function unless the attribute explicitly
supports a class, Resource, Data class, or action target.

Example of a deliberate override:

```php
#[Group('Orders', version: 'v2')]
#[Authenticated(scheme: 'default')]
#[Response(
    status: 202,
    resource: OrderResource::class,
    description: 'The order was accepted for processing.',
)]
public function store(StoreOrderRequest $request): OrderResource
{
    // ...
}
```

## Configure cross-cutting behavior

- Enable docs explicitly with `DOCUMENTATOR_ENABLED=true`; protect private docs
  with configured route middleware and/or `Documentator::auth(...)`.
- Map auth middleware to schemes in `documentator.auth_middleware`; define
  schemes under `documentator.security`; use global authentication only when
  every operation should inherit it.
- Configure `grouping.sections` to publish separate UI/spec surfaces such as
  `/docs/api` and `/docs/api/openapi.json`.
- Define repeated placeholders such as `{locale}` or `{tenant}` once with
  `global_path_parameters`.
- Keep `routes.match`, exclusions, and middleware exclusions aligned with the
  intended public API surface.

## Diagnose unexpected output

### Missing operation

1. Run `php artisan route:list` and confirm the route exists.
2. Check `routes.match`, `routes.exclude`, and `routes.exclude_middleware`.
3. Check `#[Hidden]`.
4. Confirm the action is an introspectable controller method or closure.
5. Run `documentator:check`; move an unreadable action to a controller/closure
   when the audit reports it cannot be introspected.

Do not assume generation silently skipped the route.

### Missing or generic success schema

Add a concrete return type/readable return expression or an explicit
`#[Response]`. Use `documentator:explain METHOD /uri` to see which extraction
strategy contributed or overrode the response.

### Parameter in the wrong place

Check the HTTP method first. Expect GET/HEAD validation in URI query parameters
and QUERY validation in request content. Then inspect inline PHPDoc refinements
and explicit parameter/body attributes for overrides.

### Unexpected auth, grouping, or status

Trace the operation, then inspect middleware/config and class/method attributes.
Remember that explicit attributes run after inference.

## Use commands accurately

```bash
php artisan documentator:check                         # introspectability, success schemas, health, OpenAPI checks
php artisan documentator:check --strict                # fail when documentation issues exist
php artisan documentator:check --json                  # machine-readable audit output
php artisan documentator:check --suggest-hidden        # flag likely internal/operational routes
php artisan documentator:check --against=openapi.json  # compare a committed contract
php artisan documentator:explain METHOD /api/path      # show inference/override provenance
php artisan documentator:generate                      # build the cached document
php artisan documentator:export openapi.json           # export OpenAPI JSON
php artisan documentator:postman                       # export a Postman collection
```

Describe `documentator:check` precisely: it audits action introspectability and
success schemas, reports health warnings, runs Documentator's OpenAPI checks,
and optionally compares drift. Do not present it as exhaustive discovery of
every undocumented parameter.

## Finish with this checklist

1. Confirm route inclusion and method semantics.
2. Confirm request fields are in the correct location.
3. Confirm summary/description and a concrete success schema.
4. Confirm auth, group/version, errors, status, and media type.
5. Remove redundant attributes that duplicate readable code.
6. Run `documentator:explain` for the operation.
7. Run `documentator:check` and relevant feature/contract tests.
