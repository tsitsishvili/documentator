# Changelog

All notable changes to `tsitsishvili/documentator` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/tsitsishvili/documentator/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/tsitsishvili/documentator/releases/tag/v1.0.0
