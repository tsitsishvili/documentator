# Upgrading Documentator

This guide covers breaking changes between major Documentator releases. Apply
each relevant section in order when skipping multiple major versions.

## Upgrading from 1.x to 2.0

Documentator 2.0 changes generated documents from OpenAPI 3.1 to **OpenAPI
3.2**. This is required to represent HTTP `QUERY` as a standard Path Item
operation.

Existing Laravel routes, PHP namespaces, configuration keys, and Documentator
attributes do not require source-code changes. The migration risk is downstream:
every system consuming the generated OpenAPI document must accept OpenAPI 3.2.

### Before upgrading

1. Inventory OpenAPI consumers, including validators, documentation renderers,
   gateways, client generators, contract tests, and internal tooling.
2. Confirm that each consumer supports OpenAPI 3.2. Remain on Documentator 1.x
   until incompatible consumers can be upgraded or replaced.
3. Preserve the current generated contract so endpoint-level drift can be
   reviewed after the package upgrade:

```bash
cp openapi.json openapi-v1.json
```

Use the actual committed contract path when it differs from `openapi.json`.

### Upgrade the package

```bash
composer require "tsitsishvili/documentator:^2.0" --with-all-dependencies
```

Documentator is a library and does not expose a package-version configuration
value. Composer resolves the release from the `v2.0.0` Git tag.

### Verify and regenerate artifacts

Run the documentation audit and compare the new endpoint contract with the
preserved 1.x document:

```bash
php artisan documentator:check
php artisan documentator:check --against=openapi-v1.json
```

The semantic comparison focuses on API operations and schemas; review the root
`openapi` field separately and expect it to change from `3.1.0` to `3.2.0`.

Export new artifacts to separate paths before replacing committed files:

```bash
php artisan documentator:export openapi-v2.json
php artisan documentator:postman postman-collection-v2.json
```

Review the diff, validate the new document with the downstream toolchain, then
replace the committed artifacts. If production serves cached documentation,
regenerate the configured cache after approval:

```bash
php artisan documentator:generate
```

### Adopt HTTP QUERY when needed

No route changes are required merely to upgrade. To add a safe, idempotent
operation with structured request content, register `QUERY` through Laravel's
custom-method route API:

```php
Route::match(['QUERY'], 'api/orders/search', [OrderSearchController::class, 'search']);
```

On a `QUERY` route, FormRequest, Data-object, and inline-validation input remains
request content and is emitted under `query.requestBody`. This differs from
`GET` and `HEAD`, where the same inferred input becomes URI query parameters.

### Refresh published AI guidance

Laravel Boost users should refresh package guidance after upgrading:

```bash
php artisan boost:update
```

Without Boost, republish the tool-specific guidance only after reviewing local
customizations. Laravel does not overwrite published files unless forced:

```bash
php artisan vendor:publish --tag=documentator-ai --force
```

The `--force` option replaces existing `AGENTS.md`, `GEMINI.md`, Cursor, Claude,
and generic guidance destinations managed by this publish tag. Back up or merge
locally customized files instead of overwriting them blindly.

### Roll back if necessary

If a required consumer cannot process OpenAPI 3.2, restore Documentator 1.x and
the previous generated artifacts:

```bash
composer require "tsitsishvili/documentator:^1.8" --with-all-dependencies
```

### Upgrade checklist

- [ ] All OpenAPI consumers support 3.2.
- [ ] The 1.x contract was preserved for comparison.
- [ ] `documentator:check` passes on 2.0.
- [ ] Endpoint/schema drift was reviewed separately from the version marker.
- [ ] OpenAPI, Postman, client, and cached artifacts were regenerated.
- [ ] Published AI guidance was refreshed or intentionally retained.
