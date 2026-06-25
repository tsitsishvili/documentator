# Changelog

All notable changes to `tsitsishvili/documentator` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/tsitsishvili/documentator/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/tsitsishvili/documentator/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/tsitsishvili/documentator/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/tsitsishvili/documentator/releases/tag/v1.0.0
