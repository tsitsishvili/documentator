# Documentator instructions for Codex

Apply these instructions when editing Laravel endpoints in an application using
`tsitsishvili/documentator`. The package generates **OpenAPI 3.2** from code.

## Codex workflow

Before changing an endpoint:

1. Read `config/documentator.php` and locate the route, action, request class,
   response Resource/Data/model, and relevant tests.
2. State whether the requested input belongs in the path, URI query string,
   headers/cookies, or request content. Do not confuse URI query parameters with
   the HTTP `QUERY` method.
3. Plan the smallest code-first change. Prefer Laravel types and validation over
   documentation-only attributes.

While implementing:

- Prefer `[Controller::class, 'method']` routes when controller metadata matters.
- Use a docblock whose first paragraph is the summary and remaining paragraphs
  are the description.
- Use a type-hinted `FormRequest`, inline validation, request accessors, or a
  supported Data object for input.
- Use a concrete return type or readable literal response for the success schema.
- Add Documentator attributes only for facts inference cannot derive. Attributes
  run last and intentionally override inferred values.

## Method rules

- `GET`/`HEAD` validation becomes URI query parameters, never a request body.
- `QUERY` uses `Route::match(['QUERY'], ...)`; its validation remains request
  content and becomes the OpenAPI 3.2 `query.requestBody`.
- Other body-bearing methods keep validation in the request body.

```php
Route::match(['QUERY'], 'api/orders/search', [OrderSearchController::class, 'search']);

/** Search orders using structured criteria. */
public function search(SearchOrdersRequest $request): OrderCollection
{
    return new OrderCollection(Order::search($request->validated()));
}
```

## Overrides

Use attributes from `Tsitsishvili\Documentator\Attributes` only where needed:
`Summary`, `Description`, `Group`, `OperationId`, `PathParam`, `QueryParam`,
`HeaderParam`, `CookieParam`, `BodyParam`, `RequestMediaType`, `Response`,
`ResponseHeader`, `Authenticated`, `Server`, `Hidden`, and `Deprecated`.

Do not duplicate a visible `FormRequest` field with `#[BodyParam]`, or a visible
request accessor with `#[QueryParam]`, unless correcting inference deliberately.

## Required verification

After changing an endpoint:

```bash
php artisan documentator:explain QUERY /api/orders/search
php artisan documentator:check
```

When the project commits its contract, also run:

```bash
php artisan documentator:check --against=openapi.json --fail-on=breaking
```

Inspect the generated operation when placement or schema details matter. Report
what was inferred versus overridden. `documentator:check` audits introspectable
actions, success schemas, health warnings, OpenAPI shape, and optional drift; it
does not prove that every possible request parameter is documented.

If a route is absent, inspect `routes.match`, exclusions, middleware exclusions,
`#[Hidden]`, and action introspectability before adding attributes.
