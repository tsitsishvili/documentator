# Contributing

Thanks for taking the time to contribute to `tsitsishvili/documentator`! This
package auto-generates interactive API documentation for Laravel. The notes below
should get you productive quickly.

## What this is

A **Laravel package**, not an application. There is no host app in the repo — it
is exercised through tests using [Orchestra Testbench](https://github.com/orchestral/testbench),
which boots a minimal Laravel instance. Targets **PHP 8.2+** and **Laravel 12/13**.

## Getting started

```bash
git clone https://github.com/tsitsishvili/documentator.git
cd documentator
composer install
composer test    # make sure the suite is green before you change anything
npm ci           # only needed when you touch the built-in UI / browser tests
```

## Development workflow

```bash
composer test                              # run the full Pest suite
vendor/bin/pest --filter=GeneratesOpenApi  # run a single test file / case
composer lint                              # apply Laravel Pint formatting
composer lint:test                         # check formatting without writing (CI mode)
npm run test:browser                       # Playwright visual/UI checks
```

There is no build step. Before opening a pull request, make sure **both
`composer test` and `composer lint:test` pass**. Run `npm run test:browser` too
when the built-in explorer, docs shell, or UI assets change.

## How the package is structured

The flow is a linear pipeline:

```
RouteCollector → ExtractorPipeline (ordered strategies) → OpenApiGenerator → docs UI
```

- **`src/Extraction/Strategies/`** — each strategy enriches one `EndpointData`.
  **Order matters**: inference strategies fill gaps non-destructively (`??=`) and
  `ExtractAttributes` runs **last** so explicit PHP attributes always win.
- **`src/OpenApi/`** — `OpenApiGenerator` (pure transform to an OpenAPI 3.1 array),
  `ResourceSchemaExtractor` / `DataObjectSchema` (response schemas), `SchemaSampler`.
- **`src/Attributes/`** — the override attributes, each read only by `ExtractAttributes`.
- **`src/Commands/`** — `generate`, `export`, `postman`, `check`.
- **`config/documentator.php`** — all runtime toggles.

`Documentator` (`src/Documentator.php`) is the single entry point that wires the
three stages together — controllers and the Artisan commands only ever talk to it.

The pipeline runs one mutable `EndpointData` per route through an ordered list of
strategies, and **the order is the design**: inference strategies fill gaps in
sequence, then `ExtractAttributes` runs last so attributes override everything.

1. `ExtractRouteMetadata` — verbs, URI, typed path params, name, summary/description
   from the controller method's PHPDoc.
2. `ExtractFormRequestRules` — params from a type-hinted FormRequest's `rules()`
   (query params for GET/HEAD, body params otherwise).
3. `ExtractInlineValidationRules` — literal `$request->validate([...])` arrays
   parsed from the controller body.
4. `ExtractDataObjects` — the same for spatie/laravel-data objects (no-op unless installed).
5. `ExtractResponses` — a success response from an API Resource, `ResourceCollection`,
   `Resource::collection(...)` return statement, or Eloquent model return type.
6. `ExtractInlineResponses` — literal JSON responses, service-returned arrays,
   Laravel response helpers, views and redirects from the controller body.
7. `ExtractErrorResponses` — conventional 401/403/404/422 responses inferred from shape.
8. Custom strategies from `extensions.strategies`.
9. `ExtractAttributes` — explicit PHP attributes, applied unconditionally.

Inference strategies must stay non-destructive (`$endpoint->bodyParameters[$name] ??= …`);
only `ExtractAttributes` assigns unconditionally.

### Adding a new inference source

1. Add a strategy class in `src/Extraction/Strategies/` implementing `ExtractionStrategy`.
2. Register it in the `ExtractorPipeline` binding in
   `DocumentatorServiceProvider::register()`, **before `ExtractAttributes`** so it
   stays overridable. If it is app-specific rather than package behavior, document
   it as an `extensions.strategies` example instead of adding it to the default
   package pipeline.
3. Fill gaps with `??=` — never overwrite a value another strategy set.
4. Wrap reflection, AST parsing, model instantiation and user-code evaluation in
   `try/catch`; one bad route must not break the generated document.

### Adding OpenAPI output customization

Prefer consuming `Documentator::toOpenApi()` instead of creating a second
pipeline. Organization-specific naming, tags or metadata should usually be an
`extensions.openapi_transformers` callable that receives the generated spec array
and returns the modified array.

### Adding a new attribute

Create the attribute class in `src/Attributes/` **and** handle it in
`ExtractAttributes`. Repeatable attributes carry `Attribute::IS_REPEATABLE`.

## Coding conventions

Full details are in [CODING_STANDARDS.md](CODING_STANDARDS.md). The essentials:

- `declare(strict_types=1)` at the top of every file.
- Classes are `final` and namespaced under `Tsitsishvili\Documentator\` (PSR-4 → `src/`).
- Formatting is enforced by **Laravel Pint** — run `composer lint` before committing.
- **Generation must never throw on one bad route.** Anything that can't be
  evaluated statically (a `rules()` that needs request state, an unreadable
  Resource) should be wrapped in `try/catch` and skipped, degrading to a safe
  default rather than breaking the whole document.

## Tests

- Add tests under `tests/Feature/`.
- Define throwaway controllers / FormRequests / Resources / models / Data objects
  at the top of the test file.
- Use real `[Controller::class, 'method']` routes — **closure routes skip the
  reflection-based strategies**, so they won't exercise extraction.
- UI asset changes should include or update a browser test under `tests/Browser/`.
- Every behavior change or bug fix should come with a test.

## Pull requests

- Branch off `main`; keep each PR focused on a single change.
- Update the **[Unreleased]** section of [`CHANGELOG.md`](CHANGELOG.md) and the
  `README.md` / `config/documentator.php` when you add or change behavior.
- Follow Semantic Versioning when describing the impact: bug fix = patch,
  backward-compatible feature = minor, breaking change = major.

## Reporting issues

Open an issue with the Laravel and PHP versions, a minimal route/controller that
reproduces the problem, and the OpenAPI output you got versus what you expected.
Security issues should be reported privately rather than via a public issue.
