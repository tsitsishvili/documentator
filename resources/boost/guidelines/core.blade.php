## Documentator

This application uses `tsitsishvili/documentator` to infer **OpenAPI 3.2** from
Laravel code. Keep documentation code-first: prefer typed routes, FormRequests,
validation, Resources/Data objects, and return types; use attributes only for
facts inference cannot see.

When changing an API endpoint:

- Inspect `config/documentator.php`, the route/middleware, action, request type,
  response type/expression, and tests.
- Put the first summary paragraph and following description in the method docblock.
- On `GET`/`HEAD`, validation becomes URI query parameters, not a request body.
- For HTTP `QUERY`, use `Route::match(['QUERY'], ...)`; validation remains request
  content and becomes the OpenAPI 3.2 `query.requestBody`.
- Do not confuse HTTP `QUERY` request content with URI query parameters.
- Add `Tsitsishvili\Documentator\Attributes` only for gaps or intentional overrides.
- Verify with `php artisan documentator:explain METHOD /uri` and
  `php artisan documentator:check`.
- In feature tests, use `->assertMatchesDocumentation()` on changed endpoint
  responses.

Use the **`documentator-api-docs`** skill for the full workflow, inference map,
attribute guidance, troubleshooting, contract checks, and examples.
