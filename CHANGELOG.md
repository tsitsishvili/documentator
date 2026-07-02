# Changelog

All notable changes to `tsitsishvili/documentator` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Documented headers, cookies and operation metadata.** New `#[HeaderParam]`,
  `#[CookieParam]`, `#[ResponseHeader]`, `#[OperationId]`, `#[RequestMediaType]`,
  `#[Server]`, `#[TagDescription]` and `#[SchemaName]` attributes expose more of
  OpenAPI without custom transformers. The built-in UI now shows documented
  request headers/cookies, includes them in try-it requests and snippets, and
  displays documented response headers.
- **Manual PHPDoc type-string schemas.** Attribute `type` values can now express
  useful schemas with scalars, nullable unions, `list<T>`, `T[]`,
  `array<string, T>` and array shapes such as
  `array{id: int, status?: string}`. `#[Response(type: ...)]` can document a
  response body without a Resource or example.
- **Deeper inline request inference.** Literal `Validator::make(..., [...])`
  rules and request accessors such as `$request->integer('page')`,
  `$request->boolean('active')`, `$request->query('q')` and `request('q')` are
  now documented automatically.
- **Spatie Query Builder inference.** Literal `allowedFilters`,
  `allowedSorts`, `defaultSort`, `allowedIncludes` and `allowedFields` calls are
  inferred from source, including local variable arrays, class constants, simple
  aliases and literal ignored filter values.
- **JSON:API and pagination integrations.** Laravel `JsonApiResource` responses
  are emitted with `application/vnd.api+json`, JSON:API `data` envelopes,
  `include` / `fields[type]` query parameters, and `jsonPaginate()`
  `page[number]` / `page[size]` parameters.
- **Laravel Actions inference.** Routes pointing at action `asController()`
  methods can now infer request rules from `rules()` and response schemas from
  `handle()` return types.
- **Inline parameter PHPDoc.** Inline validation and request-accessor inference
  now honors nearby `@var`, `@example`, `@default`, `@query`, `@body` and
  `@ignoreParam` tags.
- **Validation parser extensibility.** Validation keys now respect escaped dots
  such as `user\.uuid`, `exists` / inline `Rule::exists` and `Rule::when` are
  understood better, and custom rule transformers can be registered through
  `extensions.validation_rule_transformers`.

## [1.5.0] - 2026-06-28

### Added

- **Sectioned documentation surfaces.** Configure `grouping.sections` to split a
  large app into stable docs surfaces such as `/docs/api` and `/docs/app`, each
  with its own filtered OpenAPI document (`/{section}/openapi.json`). The root
  `/docs` redirects to the first configured section, and `documentator:generate`
  writes matching split cache files next to the full cached spec.
- **Path-based grouping controls.** `grouping.source`, `path_depth`,
  `ignore_path_prefixes` and `ignore_path_parameters` let controller-less routes
  and localized/tenant routes group by meaningful path segments instead of
  falling back to a generic "Endpoints" bucket.
- **Global path parameter metadata.** `global_path_parameters` describes shared
  placeholders like `{pathlang}` or `{tenant}` once, applies that metadata to
  every matching operation parameter, emits `x-documentator-global` on each use,
  and publishes the shared definitions under
  `x-documentator-global-path-parameters`.
- **Route exclusion by middleware.** `routes.exclude_middleware` filters routes
  by middleware alias or class pattern, useful for internal/docs-only/admin
  surfaces that share URI prefixes with public API routes.
- **Configurable auth middleware aliases.** `auth_middleware` maps custom
  middleware aliases (for example `internal.auth`) to OpenAPI security schemes,
  while still supporting guard-aware `auth:*` defaults.
- **Richer `documentator:check` output.** The check command now prints
  documentation health metrics, supports `--json` for dashboards, and adds
  `--suggest-hidden` to flag suspicious internal, debug, operational or tooling
  routes that may need `#[Hidden]`, `routes.exclude`, or
  `routes.exclude_middleware`.
- **More inline response inference.** `ExtractInlineResponses` now recognizes
  common Laravel response helpers, service-returned arrays, plain-text
  responses, views and redirects, including non-JSON media types such as
  `text/plain` and `text/html`.

### Changed

- **Built-in UI navigation scales further.** The explorer now persists method /
  search filters, supports collapsed groups, and virtualizes the sidebar so large
  specs remain responsive.
- **Controller-less routes get better default tags.** In automatic grouping mode,
  routes without controller methods are grouped from their path instead of always
  landing under "Endpoints".
- **Response emission preserves media type.** Explicit and inferred responses can
  now be emitted as JSON, plain text, HTML or redirect-style responses instead of
  forcing all documented content under `application/json`.

## [1.4.0] - 2026-06-27

### Added

- **Inline `$request->validate([...])` inference.** A new
  `ExtractInlineValidationRules` strategy parses literal validation arrays in the
  controller body and documents them as parameters (query for GET/HEAD, body
  otherwise) — so endpoints that validate inline instead of via a FormRequest are
  no longer blank. Dynamic rule variables are skipped; attributes still override.
- **Inline JSON response inference.** `ExtractInlineResponses` reads literal
  `return response()->json([...], 202)` returns and documents the resulting status
  and body shape, complementing the Resource/model return-type inference.
- **OpenAPI document validation in `documentator:check`.** A new `OpenApiValidator`
  runs lightweight 3.1 sanity checks (broken `$ref`s, malformed path / operation /
  schema shapes) against the emitted document; any error fails the command
  regardless of `--strict`.
- **API versioning via `#[Group(version: 'v2')]`.** Keep one public group name
  across versions: the version is emitted as `x-documentator-group-version`
  (and `x-documentator-version` on the tag), shown as a group badge in the
  built-in UI, prefixes generated operation IDs to avoid collisions, and splits
  Postman folders per version.
- **Configurable explorer auth storage.** `ui.auth_storage`
  (`DOCUMENTATOR_AUTH_STORAGE`) selects where the try-it console persists the auth
  token — `local` (default), `session`, or `memory` (never persisted).

### Changed

- **Scheme-aware Postman auth.** The Postman export now emits `apikey`, `basic`,
  or `bearer` auth matching the operation's security scheme (and honours
  root-level `security`), instead of always emitting bearer.
- **Native OpenAPI 3.1 nullability.** The generator rewrites the internal
  `nullable: true` flag to JSON Schema's `type: [..., "null"]` form throughout
  nested `properties` / `items` / `oneOf` / `anyOf` / `allOf`, so the public
  document uses the 3.1-native representation.

## [1.3.0] - 2026-06-26

### Added

- **Extension hooks.** Register custom extraction strategies
  (`extensions.strategies`) — resolved from the container and inserted just before
  `ExtractAttributes`, so attributes still override them — and OpenAPI
  transformers (`extensions.openapi_transformers`) that receive the generated spec
  array and may return a modified one for organization-specific naming or metadata.
- **Per-guard security schemes.** `auth:<guard>` middleware now maps to a
  configured security scheme whose key matches the guard name, falling back to
  `default`, instead of always emitting `default`.
- **Token/ability scopes.** `abilities:` / `ability:` / `scopes:` / `scope:`
  middleware are surfaced as the operation's security scopes in its OpenAPI
  security requirement.
- **Inferred collection & paginated responses from the method body.**
  `ExtractResponses` now statically parses a `Resource::collection(...)` return
  statement, documenting a `{ data: [...] }` collection — or the full paginated
  shape plus `page` / `per_page` query parameters when the argument calls
  `paginate()` / `simplePaginate()` / `cursorPaginate()` — even when the method's
  return type is only `AnonymousResourceCollection`.
- **Reusable response schema components.** A response schema shared by two or more
  operations is hoisted into `components/schemas` and referenced by `$ref`
  (named from the resource and its kind — `…Collection` / `…Paginated`) instead of
  being inlined repeatedly.
- **Contract-aware drift report.** `documentator:check --against` now lists the
  specific path / operation / response changes (new `Support/OpenApiDiff`) instead
  of only reporting that the spec drifted.
- **More request-snippet languages.** The explorer's snippet pane adds Go, Ruby,
  Java, C# and HTTPie generators (in the **Other** dropdown).
- **Copy endpoint link.** Each endpoint header gains a **Link** button that copies
  a deep link to that operation to the clipboard.
- **`#[Response(paginationLinks: false)]`.** Opt a custom collection out of
  Laravel's `links` blocks when it drops them from the paginator shape.

### Changed

- **Pagination schema reflects custom collections.** `PaginationSchema` honours a
  `ResourceCollection::paginationInformation()` override, pruning the documented
  `links` / `meta` to what the collection actually returns, and now documents the
  `meta.links` page-link array.
- **Richer API Resource parsing.** `mergeWhen([...])` / `merge([...])` blocks have
  their fields inlined (marked nullable when conditional), more `when*` helpers are
  recognized (`whenCounted`, `whenAggregated`, `whenPivotLoadedAs`, …), and
  `*_count` fields infer `integer`.
- **Smarter generated examples.** `SchemaSampler` is now field-name aware
  (`email`, `uuid`, `url`, `*_name`, `title`, `description`, `*_at`, `*_date`),
  respects `minItems`, and resolves `oneOf` / `anyOf` / `allOf`.

### Fixed

- **Clipboard copy fallback.** Copy buttons (snippets, responses, endpoint link)
  fall back to a `textarea` + `execCommand` copy so they work outside secure
  contexts and on older browsers.
- **Snippet highlighting.** Syntax colouring now recognizes `//` comments and the
  keywords used by the newly added languages.

## [1.2.0] - 2026-06-25

### Added

- **Resizable request panel.** The built-in explorer's right-side request /
  response panel can now be resized on desktop, remembers the chosen width, and
  keeps the existing off-canvas behavior on smaller screens.
- **Clear request inputs.** The try-it console now has a Clear button that resets
  path, query, body, raw JSON and file inputs while preserving server, auth and
  snippet-language preferences.
- **Collapsible JSON responses.** Live JSON responses now render as an expandable
  tree with per-object toggles plus Expand all / Collapse all controls. Copy
  still copies the full formatted response body.
- **Richer explorer navigation.** The built-in UI now shows endpoint counts,
  method counts, sidebar summaries, endpoint metadata and an explicit Try it
  action for opening the console on smaller screens.
- **TypeScript request snippets.** The explorer's snippet pane now offers a
  TypeScript generator alongside cURL, PHP, JS and Python. It emits typed
  `Request` / `Response` interfaces derived from the endpoint's schemas and an
  `async` `fetch` wrapper, including automatic `Date` hydration for `date` /
  `date-time` fields and `FormData` handling for multipart bodies.

### Changed

- **Improved response readability.** Try-it responses now appear in a dedicated
  result panel with a pending state, clearer status/meta sections, larger body
  area and automatic scroll-into-view after a request completes.
- **Refined explorer styling and responsive layout.** The built-in UI has a
  quieter visual treatment, tighter card radii, better mobile wrapping and fewer
  layout overflows for long paths.
- **Reorganized the snippet language switcher.** cURL, PHP, JS and TypeScript are
  shown as primary tabs; additional languages (Python) live in an **Other**
  dropdown. The previously selected language is still remembered.
- **Renamed snippet language labels** — "Laravel" → **PHP** and "JavaScript" →
  **JS** — to match the broader language set.

### Fixed

- **Versioned built-in assets.** The docs shell now appends a file timestamp to
  built-in CSS/JS asset URLs so browsers pick up package UI changes immediately
  instead of holding stale cached assets.
- **Desktop Try it overlay.** Clicking Try it on desktop no longer activates the
  mobile scrim over the already-visible request panel.

## [1.1.0] - 2026-06-25

### Added

- **Controller PHPDoc → summary & description.** The method docblock's first
  paragraph becomes the summary and the rest the Markdown description, overridable
  by `#[Summary]` / `#[Description]`.
- **spatie/laravel-data support.** Data objects type-hinted as a controller
  argument (request) or return type (response) are documented from their typed
  properties — enums, nested Data, `DateTime`, `#[DataCollectionOf]`, nullability.
  Auto-detected and a no-op when the package isn't installed.
- **Eloquent model return types.** A controller returning a model (not a Resource)
  now produces a response schema from the model's `$casts` and `@property` docblock.
- **Generated examples.** Every request body and parameter without an explicit
  example gets a representative, format-aware one (`email` → `user@example.com`,
  enums, dates, …). Toggle with `generate_examples`.
- **Conventional success status codes.** POST → `201`, DELETE → `204` instead of
  always `200`. Toggle with `infer_status_codes`.
- **Pagination query parameters.** Paginated/collection responses now document
  `page` and `per_page` query parameters.
- **`documentator:check` command.** Audits the docs for gaps (closure routes,
  missing success schema); `--strict` fails CI on issues and `--against=<spec>`
  fails on drift from a committed spec.
- **Richer validation-rule coverage.** `Rule::enum()` / `Rule::in()` rule objects,
  `regex:` → `pattern`, `digits` / `digits_between` → integer, all-numeric enums
  (int-backed) → integer enum, and `confirmed` → a mirrored `{field}_confirmation`.

### Changed

- **Typed path parameters.** Path parameters are typed `integer` when the route
  has a numeric constraint (`->whereNumber()`) or binds a model with an integer
  key, instead of always `string`.
- **GET/HEAD FormRequest rules become query parameters** rather than a request
  body, which OpenAPI clients ignore for those verbs.

## [1.0.0]

### Added

- Initial release: route scanning, FormRequest / API Resource / return-type
  inference, PHP attribute overrides, OpenAPI 3.1 output, built-in interactive UI
  (with a Scalar driver), Postman export, and the `documentator:generate`,
  `documentator:export` and `documentator:postman` commands.

[Unreleased]: https://github.com/tsitsishvili/documentator/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/tsitsishvili/documentator/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/tsitsishvili/documentator/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/tsitsishvili/documentator/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/tsitsishvili/documentator/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/tsitsishvili/documentator/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/tsitsishvili/documentator/releases/tag/v1.0.0
