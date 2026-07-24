# Documentator Codebase Map

This guide explains where the main behavior lives and which files to open when
you need to find or change a specific fragment of the package.

For the built-in browser UI only, see `docs/built-in-ui-code-map.md`.

## Big Picture

Documentator is a Laravel package that scans host-app routes, enriches each
route with inferred API documentation, turns the result into OpenAPI 3.2, and
serves or exports that document.

Runtime flow:

1. `src/DocumentatorServiceProvider.php` registers config, services, routes, and
   console commands.
2. `src/Documentator.php` asks `RouteCollector` for documentable routes.
3. `src/Extraction/ExtractorPipeline.php` runs each route through ordered
   extraction strategies.
4. The strategies fill `EndpointData`, `ParameterData`, and `ResponseData`.
5. `src/OpenApi/OpenApiGenerator.php` converts endpoint data into an OpenAPI
   3.2 array.
6. HTTP controllers, Artisan commands, the Postman generator, and feature-test
   contract assertions consume the OpenAPI array.

## Main Entry Points

| File                                  | What Happens There                                                                                                                                                              |
|---------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `src/Documentator.php`                | High-level API: `endpoints()` returns extracted endpoint objects, `toOpenApi()` returns the generated spec, and `auth()` / `check()` add per-request access control after docs are explicitly enabled. |
| `src/DocumentatorServiceProvider.php` | Laravel integration: merges config, binds collector/pipeline/generator services, registers docs/OpenAPI/asset routes, registers commands, and publishes config/views.           |
| `config/documentator.php`             | All package configuration defaults: route matching, docs route, UI driver, grouping, global path parameters, auth, status-code inference, examples, cache, and extension hooks. |
| `composer.json`                       | Package metadata, Laravel provider discovery, dependencies, optional integrations, and scripts.                                                                                 |

## Request To OpenAPI Flow

| Step                        | Files                                        | Notes                                                                                                                 |
|-----------------------------|----------------------------------------------|-----------------------------------------------------------------------------------------------------------------------|
| Collect Laravel routes      | `src/Extraction/RouteCollector.php`          | Filters registered routes by `documentator.routes.match`, `exclude`, and `exclude_middleware`.                        |
| Create endpoint accumulator | `src/Data/EndpointData.php`                  | Mutable object that all strategies enrich. It also creates default operation IDs.                                     |
| Run strategies              | `src/Extraction/ExtractorPipeline.php`       | Reflects the route action, invokes each strategy in order, and records per-facet provenance for `documentator:explain`. |
| Convert to OpenAPI          | `src/OpenApi/OpenApiGenerator.php`           | Builds `info`, `servers`, `tags`, `paths`, parameters, request bodies, responses, security, examples, and components. |
| Serve OpenAPI               | `src/Http/Controllers/OpenApiController.php` | Returns generated JSON, cached JSON, or a section-filtered spec.                                                      |
| Serve docs UI               | `src/Http/Controllers/DocsController.php`    | Chooses built-in UI vs Scalar and passes spec/asset URLs to the Blade view.                                           |

## Extraction Pipeline

The pipeline order is declared in `src/DocumentatorServiceProvider.php`.
Inference runs first; `ExtractAttributes` runs last so explicit PHP attributes
override inferred values.

| Strategy             | File                                                         | What It Adds                                                                                                                                          |
|----------------------|--------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------|
| Route metadata       | `src/Extraction/Strategies/ExtractRouteMetadata.php`         | HTTP verbs, URI, route name, controller/method, introspectability, default summary, PHPDoc summary/description, path params, auth middleware, scopes. |
| FormRequest rules    | `src/Extraction/Strategies/ExtractFormRequestRules.php`      | Body or query params from type-hinted `FormRequest::rules()`. GET/HEAD rules become query params.                                                     |
| Laravel Actions      | `src/Extraction/Strategies/ExtractLaravelActions.php`        | Rules and return types for `lorisleiva/laravel-actions` style actions.                                                                                |
| Inline validation    | `src/Extraction/Strategies/ExtractInlineValidationRules.php` | Params from `$request->validate()`, `request()->validate()`, `Validator::make()`, and request accessors.                                              |
| Spatie Data          | `src/Extraction/Strategies/ExtractDataObjects.php`           | Request params and response schemas from `spatie/laravel-data` objects.                                                                               |
| Spatie Query Builder | `src/Extraction/Strategies/ExtractSpatieQueryBuilder.php`    | Query params from `allowedFilters`, `allowedSorts`, `allowedIncludes`, `allowedFields`, and `defaultSort`.                                            |
| Return responses     | `src/Extraction/Strategies/ExtractResponses.php`             | Success responses from API Resource, ResourceCollection, model, collection, and paginator return shapes.                                              |
| Inline responses     | `src/Extraction/Strategies/ExtractInlineResponses.php`       | Success responses from literal inline JSON responses like `response()->json([...], 202)`.                                                             |
| Error responses      | `src/Extraction/Strategies/ExtractErrorResponses.php`        | Conventional and control-flow errors from auth, validation, model binding, `abort*`, Gate/controller authorization, and recognized HTTP exceptions. |
| Attributes           | `src/Extraction/Strategies/ExtractAttributes.php`            | Explicit overrides from `#[Summary]`, `#[Description]`, `#[Response]`, params, auth, grouping, servers, hidden/deprecated flags, and headers.         |

## Extraction Helpers

| File                                                        | Purpose                                                                                                                                                                                          |
|-------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `src/Extraction/Support/RouteActionReflection.php`          | Converts controller methods or closure routes into reflection objects used by strategies.                                                                                                        |
| `src/Extraction/Support/SourceAnalyzer.php`                 | Shared PHP-parser wrapper. Reads source files, caches ASTs, resolves names, and finds method/closure nodes.                                                                                      |
| `src/Extraction/Support/RuleParser.php`                     | Converts Laravel validation rules into `ParameterData` with nested OpenAPI schemas, required fields, enums, formats, bounds, nullability, uploads, and `confirmed` fields.                       |
| `src/Extraction/Support/InlineValidationRulesExtractor.php` | Finds inline validation arrays and request-accessor fragments in controller/closure source; reads inline PHPDoc tags like `@var`, `@example`, `@default`, `@query`, `@body`, and `@ignoreParam`. |

## Data Objects

| File                         | Represents                                                                                                                          |
|------------------------------|-------------------------------------------------------------------------------------------------------------------------------------|
| `src/Data/EndpointData.php`  | One documented endpoint: route metadata, grouped params, request body, responses, auth, servers, grouping, visibility, and internal provenance trace. |
| `src/Data/ParameterData.php` | One path/query/header/cookie/body parameter. `schema` wins over scalar `type` when present.                                         |
| `src/Data/ResponseData.php`  | One response status, including description, example, resource/type/schema, media type, headers, and reusable schema name.           |

These are mutable on purpose: every extraction strategy receives and returns the
same endpoint accumulator.

## Attributes

Attribute classes live in `src/Attributes`.

| Area                    | Attributes                                                                               |
|-------------------------|------------------------------------------------------------------------------------------|
| Endpoint text           | `Summary`, `Description`, `OperationId`                                                  |
| Grouping and visibility | `Group`, `TagDescription`, `Hidden`, `Deprecated`                                        |
| Auth and servers        | `Authenticated`, `Server`                                                                |
| Request docs            | `PathParam`, `QueryParam`, `HeaderParam`, `CookieParam`, `BodyParam`, `RequestMediaType` |
| Response docs           | `Response`, `ResponseHeader`, `SchemaName`                                               |
| Resource inference      | `UsesModel`                                                                              |

The attribute reader is `src/Extraction/Strategies/ExtractAttributes.php`.
Schema name and model hints are also read by OpenAPI schema extractors when
resource schemas are built.

## OpenAPI Generation

| File                                      | What Happens There                                                                                                                                                                     |
|-------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `src/OpenApi/OpenApiGenerator.php`        | Turns endpoint data into OpenAPI 3.2. Look here for parameter rendering, request bodies, response content, security, tags, sections, components, examples, and extension transformers. |
| `src/OpenApi/OpenApiSections.php`         | Builds configured documentation sections and filters a full spec into per-section specs.                                                                                               |
| `src/OpenApi/ResourceSchemaExtractor.php` | Parses Laravel API Resource `toArray()` methods and Eloquent model docblocks/casts into response schemas. Handles JSON:API resources too.                                              |
| `src/OpenApi/DataObjectSchema.php`        | Builds request/response schemas for `spatie/laravel-data` objects.                                                                                                                     |
| `src/OpenApi/PaginationSchema.php`        | Shared Laravel paginator and collection envelopes plus pagination query parameters.                                                                                                    |
| `src/OpenApi/SchemaSampler.php`           | Creates representative examples from schemas for generated examples and Postman bodies.                                                                                                |
| `src/OpenApi/SchemaType.php`              | Name/rule/accessor based schema-type heuristics.                                                                                                                                       |
| `src/OpenApi/TypeStringParser.php`        | Parses PHPDoc/PHPStan-style type strings used in attributes and inline docs.                                                                                                           |

## HTTP Docs Surface

| File                                         | Purpose                                                                           |
|----------------------------------------------|-----------------------------------------------------------------------------------|
| `src/Http/Controllers/DocsController.php`    | Serves the docs page. Redirects `/docs` to the first section when sections exist. |
| `src/Http/Controllers/OpenApiController.php` | Serves OpenAPI JSON, optionally from the configured cache.                        |
| `src/Http/Controllers/AssetController.php`   | Serves whitelisted built-in UI assets.                                            |
| `src/Http/Middleware/EnsureDocsEnabled.php`  | Blocks docs routes unless `documentator.enabled` / `DOCUMENTATOR_ENABLED` explicitly enables them. |
| `src/Http/Middleware/Authorize.php`          | Calls `Documentator::check()` for optional custom docs access control.            |
| `resources/views/docs.blade.php`             | HTML shell for the built-in explorer.                                             |
| `resources/views/scalar.blade.php`           | HTML shell for Scalar.                                                            |
| `resources/ui/app.js`                        | Built-in UI entrypoint.                                                           |
| `resources/ui/core.js`                       | Built-in explorer behavior.                                                       |
| `resources/ui/snippets.js`                   | Request snippet generation.                                                       |
| `resources/ui/app.css`                       | Built-in explorer styles.                                                         |

See `docs/built-in-ui-code-map.md` for a deeper UI-only map.

## Console Commands

Commands are registered in `src/DocumentatorServiceProvider.php`.

| Command                 | File                               | What It Does                                                                                                                  |
|-------------------------|------------------------------------|-------------------------------------------------------------------------------------------------------------------------------|
| `documentator:generate` | `src/Commands/GenerateCommand.php` | Generates and caches OpenAPI JSON, including section-specific cache files.                                                    |
| `documentator:export`   | `src/Commands/ExportCommand.php`   | Writes the generated OpenAPI document to a chosen JSON path.                                                                  |
| `documentator:postman`  | `src/Commands/PostmanCommand.php`  | Writes a Postman Collection generated from OpenAPI.                                                                           |
| `documentator:check`    | `src/Commands/CheckCommand.php`    | Audits docs quality, validates the emitted OpenAPI shape, suggests hidden routes, and detects drift against a committed spec. |
| `documentator:explain`  | `src/Commands/ExplainCommand.php`  | Shows which extraction strategies inferred or overrode every documented facet for one operation.                       |

## Postman And Validation Support

| File                               | Purpose                                                                                                                          |
|------------------------------------|----------------------------------------------------------------------------------------------------------------------------------|
| `src/Postman/PostmanGenerator.php` | Converts OpenAPI operations into Postman Collection v2.1 folders, requests, variables, auth, query/path params, and JSON bodies. |
| `src/Support/OpenApiValidator.php` | Lightweight OpenAPI sanity checker used by `documentator:check`.                                                                 |
| `src/Support/OpenApiDiff.php`      | Human-readable OpenAPI diff used for drift checks.                                                                               |

## Runtime Contract Verification

| File                                           | Purpose                                                                                                                |
|------------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| `src/Testing/TestResponseContract.php`         | Connects Laravel's `TestResponse` to the generated document and raises PHPUnit assertion failures.                    |
| `src/Testing/OpenApiResponseValidator.php`     | Matches the request path/method and validates response status, media type, JSON decoding, and the selected schema.     |
| `src/Testing/JsonSchemaValidator.php`          | Validates runtime values against Documentator's emitted JSON Schema vocabulary, including refs and composites.        |
| `src/DocumentatorServiceProvider.php`          | Registers the `assertMatchesDocumentation()` `TestResponse` macro and its cached per-test-container contract service. |

## Common Fragment Finder

| If You Need To Change...                         | Start Here                                                                                                                 |
|--------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------|
| Which routes are documented                      | `src/Extraction/RouteCollector.php`, `config/documentator.php`                                                             |
| Service bindings or pipeline order               | `src/DocumentatorServiceProvider.php`                                                                                      |
| How endpoint summaries/descriptions are inferred | `src/Extraction/Strategies/ExtractRouteMetadata.php`                                                                       |
| Auth middleware detection and scopes             | `src/Extraction/Strategies/ExtractRouteMetadata.php`                                                                       |
| Route path parameter typing                      | `src/Extraction/Strategies/ExtractRouteMetadata.php`                                                                       |
| FormRequest rule parsing                         | `src/Extraction/Strategies/ExtractFormRequestRules.php`, `src/Extraction/Support/RuleParser.php`                           |
| Inline validation parsing                        | `src/Extraction/Strategies/ExtractInlineValidationRules.php`, `src/Extraction/Support/InlineValidationRulesExtractor.php`  |
| Request accessor inference                       | `src/Extraction/Support/InlineValidationRulesExtractor.php`                                                                |
| `spatie/laravel-data` support                    | `src/Extraction/Strategies/ExtractDataObjects.php`, `src/OpenApi/DataObjectSchema.php`                                     |
| `spatie/laravel-query-builder` support           | `src/Extraction/Strategies/ExtractSpatieQueryBuilder.php`                                                                  |
| Response inference from resources/models         | `src/Extraction/Strategies/ExtractResponses.php`, `src/OpenApi/ResourceSchemaExtractor.php`                                |
| Inline `response()->json()` inference            | `src/Extraction/Strategies/ExtractInlineResponses.php`                                                                     |
| Inferred 401/403/404/422 responses               | `src/Extraction/Strategies/ExtractErrorResponses.php`                                                                      |
| PHP attributes and explicit overrides            | `src/Attributes/*`, `src/Extraction/Strategies/ExtractAttributes.php`                                                      |
| OpenAPI parameter/request/response rendering     | `src/OpenApi/OpenApiGenerator.php`                                                                                         |
| Reusable component schemas                       | `src/OpenApi/OpenApiGenerator.php`, `src/OpenApi/ResourceSchemaExtractor.php`, `src/OpenApi/DataObjectSchema.php`          |
| Generated examples                               | `src/OpenApi/SchemaSampler.php`, `src/OpenApi/OpenApiGenerator.php`                                                        |
| Sectioned docs/specs                             | `src/OpenApi/OpenApiSections.php`, `src/Http/Controllers/DocsController.php`, `src/Http/Controllers/OpenApiController.php` |
| Built-in docs UI                                 | `docs/built-in-ui-code-map.md`, `resources/ui/core.js`, `resources/ui/snippets.js`, `resources/ui/app.css`                 |
| Scalar UI shell                                  | `resources/views/scalar.blade.php`, `src/Http/Controllers/DocsController.php`                                              |
| Asset route whitelist                            | `src/DocumentatorServiceProvider.php`, `src/Http/Controllers/AssetController.php`                                          |
| Cached spec generation                           | `src/Commands/GenerateCommand.php`, `src/Http/Controllers/OpenApiController.php`                                           |
| Exported OpenAPI file                            | `src/Commands/ExportCommand.php`                                                                                           |
| Postman export                                   | `src/Commands/PostmanCommand.php`, `src/Postman/PostmanGenerator.php`                                                      |
| Docs quality checks                              | `src/Commands/CheckCommand.php`, `src/Support/OpenApiValidator.php`, `src/Support/OpenApiDiff.php`                         |
| Runtime response contract assertions             | `src/Testing/TestResponseContract.php`, `src/Testing/OpenApiResponseValidator.php`, `src/Testing/JsonSchemaValidator.php`  |
| Config defaults                                  | `config/documentator.php`                                                                                                  |
| Package discovery and scripts                    | `composer.json`                                                                                                            |

## Test Map

| Behavior                                 | Tests                                                                                                                                                            |
|------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Core OpenAPI generation                  | `tests/Feature/GeneratesOpenApiTest.php`, `tests/Feature/OpenApiValidationTest.php`                                                                              |
| Inference accuracy and overrides         | `tests/Feature/InferenceAccuracyTest.php`, `tests/Feature/InfersAndOverridesTest.php`, `tests/Feature/AdvancedInferenceTest.php`                                 |
| FormRequest and inline validation        | `tests/Feature/InlineValidationTest.php`, `tests/Feature/RuleParserExtrasTest.php`                                                                               |
| Docblocks and type parsing               | `tests/Feature/DocblockTest.php`, `tests/Feature/DocblockTypingTest.php`                                                                                         |
| Resources, schemas, pagination, examples | `tests/Feature/ResourceSchemaTest.php`, `tests/Feature/ResourceTypesTest.php`, `tests/Feature/RichSchemaTest.php`, `tests/Feature/PaginationAndExamplesTest.php` |
| Data objects                             | `tests/Feature/DataObjectTest.php`                                                                                                                               |
| Error responses and auth                 | `tests/Feature/ErrorResponsesTest.php`, `tests/Feature/GlobalAuthTest.php`                                                                                       |
| Commands, validation, drift              | `tests/Feature/CheckCommandTest.php`, `tests/Feature/OpenApiDiffTest.php`                                                                                        |
| Runtime response contracts               | `tests/Feature/ContractVerificationTest.php`                                                                                                                   |
| Docs UI and assets                       | `tests/Feature/DocsUiTest.php`, `tests/Browser/built-in-ui.visual.spec.mjs`                                                                                      |
| Postman export                           | `tests/Feature/PostmanTest.php`                                                                                                                                  |
| Packaging                                | `tests/Feature/PackagingTest.php`, `tests/Feature/PackageImprovementsTest.php`                                                                                   |
| Scramble comparison/parity               | `tests/Feature/ScrambleParityTest.php`                                                                                                                           |

## Useful Commands

```bash
composer test
composer analyse
composer lint:test
node --check resources/ui/app.js resources/ui/core.js resources/ui/snippets.js
npm run test:browser
```

For a narrow PHP test run:

```bash
./vendor/bin/pest tests/Feature/GeneratesOpenApiTest.php
```
