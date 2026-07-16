# Documentator context for Gemini

When working on a Laravel application with `tsitsishvili/documentator`, generate
documentation through application code. Documentator emits **OpenAPI 3.2** and
applies explicit attributes after inference.

## Gemini context-gathering sequence

Gather these files before proposing an endpoint change:

1. `config/documentator.php` for match/exclude, auth, grouping, and inference settings.
2. The route and middleware.
3. The controller/closure and its PHPDoc.
4. The FormRequest, inline validation, request accessors, or Data input.
5. The Resource/Data/model/return expression that defines the response.
6. Existing endpoint and contract tests.

Then choose the source for each documented fact. Prefer typed Laravel code;
reserve attributes for information that is genuinely invisible to inference.

## Method and input rules

- On `GET`/`HEAD`, validation rules become URI query parameters and no request
  body is emitted.
- For HTTP `QUERY`, use `Route::match(['QUERY'], ...)`. Its structured validation
  remains request content and becomes the OpenAPI 3.2 `query` operation's body.
- URI query parameters and HTTP `QUERY` content are separate concepts. Infer URI
  parameters from request accessors or add `#[QueryParam]` only when necessary.
- Other body-bearing methods document validation as request content.

```php
Route::match(['QUERY'], 'api/reports/search', [ReportSearchController::class, 'search']);

/** Search reports. */
public function search(SearchReportsRequest $request): ReportCollection
{
    return new ReportCollection(Report::search($request->validated()));
}
```

## Preferred inference signals

- Method docblock: first paragraph → summary; remaining prose → description.
- `FormRequest`/inline validation/Data input → request schema.
- Request accessors → individual request parameters.
- Resource, ResourceCollection, model, Data return type, paginator, literal JSON,
  or response helper → success schema/status.
- Middleware, validation, model binding, authorization, `abort*`, and recognized
  exceptions → possible error responses.

Status defaults: `POST → 201`, `DELETE → 204`, otherwise `200`.

Use `Tsitsishvili\Documentator\Attributes` such as `Group`, `Authenticated`,
`QueryParam`, `BodyParam`, `Response`, `ResponseHeader`, `Server`, `Hidden`, or
`Deprecated` only to fill or intentionally override gaps.

## Validate the result

Run the operation-specific trace first, then the broader audit:

```bash
php artisan documentator:explain QUERY /api/reports/search
php artisan documentator:check
php artisan documentator:check --against=openapi.json --fail-on=breaking
```

Do not describe `documentator:check` as exhaustive parameter detection. It
checks action introspectability and success schemas, reports health warnings,
validates Documentator's emitted shape, and optionally compares contract drift.

For a missing operation, inspect route matching/exclusions, `#[Hidden]`, and
whether the action is an introspectable controller method or closure before
changing documentation metadata.
