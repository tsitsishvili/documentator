# Changelog

All notable changes to `tsitsishvili/documentator` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **PHP 8.5 support.** The test matrix now runs on PHP 8.5 alongside 8.2–8.4.
  The existing `^8.2` constraint already permitted 8.5; no consumer changes are
  required.
- **Explainable inference.** `documentator:explain METHOD URI` shows the ordered
  strategy trace behind every documented field, parameter and response; `--json`
  provides the same provenance for tooling.
- **Breaking-only contract gates.** `documentator:check --against=<spec>
  --fail-on=breaking` allows additive drift while still failing on breaking
  changes. Semantic comparison now follows local schema references and covers
  nullability, bounds, patterns, composites, additional properties, response
  headers, and security scopes.
- **Control-flow error responses.** Literal `abort`, `abort_if` and
  `abort_unless` calls, controller/Gate authorization, and recognized Laravel or
  Symfony HTTP exceptions contribute their possible 4xx/5xx responses.

### Changed

- **More accurate Resource schemas.** Unconditional Resource fields are marked
  required while `when*`/`mergeWhen` fields are optional instead of nullable.
  `array_merge(parent::toArray(), [...])` composition and field descriptions,
  `@var` types, and `@example` values are preserved.
- **Deeper Spatie Data schemas.** Input/output name mapping and `Optional`/`Lazy`
  unions now affect property names and requiredness.
- **Multiple same-status response branches.** Distinct inline response shapes
  sharing a status code are emitted under `oneOf` instead of silently keeping
  only the first branch.
- **Accurate validator wording.** The lightweight built-in validation result is
  reported as Documentator OpenAPI checks, not as full OpenAPI validity.

## [1.7.1] - 2026-07-06

### Changed

- **Completed Composer package metadata.** Declared the package type and
  maintainer details and expanded the formatted keyword list used by package
  registries.

## [1.7.0] - 2026-07-06

### Added

- **AI agent guidance for consuming apps.** The package now ships instructions
  that teach AI coding agents to write endpoints Documentator can document
  automatically and to override with attributes only where needed. Laravel Boost
  discovers them automatically on `boost:install` (an always-on guideline plus
  the on-demand `documentator-api-docs` skill, under `resources/boost/`). Without
  Boost, `php artisan vendor:publish --tag=documentator-ai` installs the same
  guidance for Claude Code (`.claude/skills/`), Cursor (`.cursor/rules/`),
  Gemini CLI (`GEMINI.md`), Codex (`AGENTS.md`) and any other agent
  (`.ai/guidelines/`).

## [1.6.3] - 2026-07-05

### Security

- **Hardened built-in try-it response rendering.** Live API responses, headers,
  pending state and error state are now mounted with DOM APIs and `textContent`
  instead of interpolating arbitrary response content into `innerHTML`, reducing
  XSS risk from hostile API responses.
- **Docs routes are disabled by default.** `documentator.enabled` now defaults
  to `false`; set `DOCUMENTATOR_ENABLED=true` to expose the UI/OpenAPI routes,
  then protect private APIs with route middleware and/or `Documentator::auth()`.
- **Try-it auth tokens stay in memory by default.** `ui.auth_storage` now
  defaults to `memory` instead of persistent `localStorage`, while `session` and
  `local` remain available for teams that explicitly choose persistence.
- **Reduced string-based UI update sinks.** Repeated live updates in the built-in
  explorer now prefer DOM construction, with remaining fixed-template rendering
  centralized behind helper functions and documented escape-first guidance.

### Changed

- **Security docs and internals docs refreshed.** README, SECURITY, coding
  standards and code maps now describe explicit docs enablement, memory auth
  storage, Scalar self-hosting guidance, and the built-in UI rendering contract.

## [1.6.2] - 2026-07-04

### Added

- **Multipart request bodies in Postman export.** `multipart/form-data` request
  bodies now export as Postman `formdata`, with binary and array-of-binary
  properties emitted as `file` fields and the remaining properties emitted as
  sampled `text` fields.
- **Path-template validation in `OpenApiValidator`.** The validator now reports
  duplicate `operationId`s and path-template mismatches — path parameters that
  are declared but missing from the template, template placeholders with no
  matching parameter, and parameter names that aren't valid OpenAPI path names.

### Fixed

- **Custom route binding fields produce valid path templates.** Routes with
  explicit binding fields such as `{product:slug}` (and typed constraints) are
  now normalized to `{product}` in OpenAPI paths and Postman URLs, and the path
  parameter is still extracted, instead of emitting an invalid path template.
- **Duplicate `operationId`s are made unique.** When several routes resolve to
  the same controller action, generated `operationId`s now receive a numeric
  suffix so each operation stays unique, as OpenAPI requires.
- **Responses keep both schema and example.** A `#[Response]` that supplies a
  schema (or a `type` string) together with an `example` now emits both under
  the media type; previously the example replaced the schema.
- **Nullable union types sample correctly.** `SchemaSampler` now resolves
  array-typed `type` values such as `['string', 'null']` to their first
  non-null type, so generated examples and Postman bodies no longer collapse
  nullable fields to `null`.
- **Cookie parameters work in the built-in "try it" console.** Documented
  cookie parameters on same-origin requests are now applied through the browser
  cookie jar (`document.cookie` with `credentials: 'include'`) instead of an
  illegal `Cookie` request header that browsers silently drop.
- **Nested multipart fields use Laravel bracket names.** Try-it requests and
  request snippets now expand nested arrays and objects in `multipart/form-data`
  bodies into bracketed field names such as `items[0][sku]`.
- **Postman export handles an empty security-scheme map.** `components.securitySchemes`
  serialized as an empty JSON object (`{}`) is coerced back to an array before
  the Postman auth helpers read it, avoiding a type error.

## [1.6.1] - 2026-07-04

### Changed

- **Built-in explorer JavaScript split into ES modules.** The former single
  `app.js` bundle is now a small entrypoint that loads `core.js` (the reading
  surface and try-it console) and `snippets.js` (request-snippet generation) as
  native ES modules via `<script type="module">`. The asset route serves
  `core.js` and `snippets.js` alongside `app.css` / `app.js`; there is still no
  build step and the explorer behaves the same.
- **Wrapped sidebar path labels.** Long endpoint paths in the explorer
  navigation now clamp to two lines with a consistent row height instead of
  breaking at arbitrary characters, keeping the virtualized sidebar aligned.

## [1.6.0] - 2026-07-02

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

[Unreleased]: https://github.com/tsitsishvili/documentator/compare/v1.7.1...HEAD
[1.7.1]: https://github.com/tsitsishvili/documentator/compare/v1.7.0...v1.7.1
[1.7.0]: https://github.com/tsitsishvili/documentator/compare/v1.6.3...v1.7.0
[1.6.3]: https://github.com/tsitsishvili/documentator/compare/v1.6.2...v1.6.3
[1.6.2]: https://github.com/tsitsishvili/documentator/compare/v1.6.1...v1.6.2
[1.6.1]: https://github.com/tsitsishvili/documentator/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/tsitsishvili/documentator/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/tsitsishvili/documentator/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/tsitsishvili/documentator/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/tsitsishvili/documentator/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/tsitsishvili/documentator/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/tsitsishvili/documentator/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/tsitsishvili/documentator/releases/tag/v1.0.0
