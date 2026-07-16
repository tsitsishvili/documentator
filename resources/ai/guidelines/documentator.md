# Documentator guidance for AI agents

Use this guidance when changing Laravel routes, controllers, FormRequests, API
Resources, Data objects, or API documentation in an application that has
`tsitsishvili/documentator` installed.

Documentator infers an **OpenAPI 3.2** document from application code. Preserve
one source of truth: write typed, idiomatic Laravel first and add Documentator
attributes only for facts inference cannot see.

## Portable workflow

1. Inspect `config/documentator.php`, the route, its action, request type, and
   response type before editing. Confirm the URI matches `routes.match` and is
   not excluded or hidden.
2. Prefer a controller action when class metadata, grouping, or inherited
   attributes matter. Typed closures still support parameter, attribute, and
   return-expression inference.
3. Add a method docblock: first paragraph is the summary; following paragraphs
   are the description.
4. Express request input with a `FormRequest`, inline validation, request
   accessors, or a supported Data object.
5. Express the response with a concrete return type or readable literal response.
6. Add attributes only for remaining gaps.
7. Run `documentator:explain` for the changed operation and
   `documentator:check` before finishing.

## Request placement

- `GET` and `HEAD`: `FormRequest`/validation rules become URI query parameters;
  do not add a request body.
- `QUERY`: register with `Route::match(['QUERY'], ...)`. Validation rules remain
  request content and are emitted as the OpenAPI 3.2 `query` operation's
  `requestBody`.
- Other body-bearing methods: validation rules become request-body fields.
- URI query parameters are distinct from HTTP `QUERY` request content. Infer
  them from request accessors such as `$request->query('q')` or document an
  invisible one with `#[QueryParam]`.

```php
Route::match(['QUERY'], 'api/orders/search', [OrderSearchController::class, 'search']);

/** Search orders. */
public function search(SearchOrdersRequest $request): OrderCollection
{
    return new OrderCollection(Order::search($request->validated()));
}
```

## Inference and overrides

- A type-hinted `FormRequest` contributes validation types, required fields,
  enums, formats, bounds, nested fields, and uploads.
- API Resources, ResourceCollections, Eloquent models, Data objects, paginator
  expressions, literal arrays, and common response helpers can contribute
  response schemas.
- Status defaults are `POST → 201`, `DELETE → 204`, otherwise `200`.
- Auth middleware, validation, model binding, authorization, `abort*`, and
  recognized HTTP exceptions can contribute error responses.
- Attributes run last. Never describe the same fact with competing inference
  patterns and attributes unless an explicit override is intended.

Useful attributes from `Tsitsishvili\Documentator\Attributes` include
`Summary`, `Description`, `Group`, `OperationId`, `PathParam`, `QueryParam`,
`HeaderParam`, `CookieParam`, `BodyParam`, `RequestMediaType`, `Response`,
`ResponseHeader`, `Authenticated`, `Server`, `Hidden`, and `Deprecated`.

## Verification and diagnosis

```bash
php artisan documentator:explain QUERY /api/orders/search
php artisan documentator:check
php artisan documentator:check --against=openapi.json --fail-on=breaking
php artisan documentator:export openapi.json
```

`documentator:check` audits action introspectability and success schemas, reports
documentation-health warnings, runs Documentator's OpenAPI checks, and can
compare a committed spec. It is not a general detector for every undocumented
parameter.

If an operation is missing, inspect route matching/exclusions, `#[Hidden]`, and
whether the action is an introspectable controller method or closure. Do not
claim that Documentator silently skipped it without checking those conditions.
