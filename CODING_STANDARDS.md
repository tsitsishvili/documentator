# Coding Standards

These are the conventions every file in `tsitsishvili/documentator` follows.
They exist so the codebase reads as if one person wrote it. New code should match
the file around it; when in doubt, copy an existing file's shape.

Formatting is enforced by **Laravel Pint** (default `laravel` preset, no
`pint.json`). Run `composer lint` before committing and `composer lint:test` in
CI — anything below that Pint can enforce, it does. The rest is convention.

## File layout

Every PHP file starts identically:

```php
<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\...;

use ...;

/**
 * One paragraph saying what this class is for and why it exists.
 */
final class Thing
{
```

- `declare(strict_types=1)` is **mandatory** on every file.
- Namespace is `Tsitsishvili\Documentator\` mapped PSR-4 to `src/`. The directory
  structure mirrors the namespace exactly.
- Imports are `use` statements at the top — no inline `\Fully\Qualified\Names` in
  the body. Pint sorts them alphabetically.
- One class per file; the filename matches the class name.

## Classes

- Classes are **`final`** by default. This package is not designed for inheritance;
  extension happens by adding a strategy, not by subclassing one.
- Prefer **constructor property promotion** with `readonly` for injected
  dependencies:

  ```php
  public function __construct(private readonly ResourceSchemaExtractor $schemas) {}
  ```
- Keep classes single-purpose. A strategy infers one thing; a generator transforms;
  a data object holds state. Don't merge responsibilities.

## Types

- **Type everything** — parameters, return types, properties. Use `void` for
  no-return methods.
- Internal `type` strings are **already OpenAPI type names** (`string`, `integer`,
  `number`, `boolean`, `array`, `object`). Don't introduce a second vocabulary or
  map between two of them.
- Use **`match`** over `switch`, and prefer `match (true)` for guard-style branching
  (see `RuleParser::typeFor`).
- Use first-class enum and named arguments where they aid readability — named
  arguments are the norm when constructing `ParameterData` / `ResponseData`.

## Docblocks

- Every class gets a short docblock explaining its role — not what each line does,
  but *why the class exists* and any non-obvious contract (see the existing
  strategy headers for tone).
- Add `@param` / `@return` **only** to type things PHP can't express: array shapes
  (`@return array<string, mixed>`), `array<int, ParameterData>`, etc. Don't repeat
  a signature the type hints already state — Pint strips superfluous tags.
- Comments explain **why**, not what. The codebase favours a sentence above a
  tricky block over a line-by-line narration.

## Naming

- Classes: `PascalCase`. Methods and variables: `camelCase`. Config keys: `snake_case`.
- Strategies are named `Extract*` (`ExtractRouteMetadata`, `ExtractDataObjects`).
- Commands are named `*Command` and use the `documentator:*` signature prefix.
- Attribute classes are bare nouns (`Summary`, `BodyParam`, `Response`).

## The cardinal rule: generation never throws

Documentation is best-effort over a whole app's routes. **One unanalysable route
must never break the document.**

- Wrap anything that evaluates user code statically or reflectively — `rules()`,
  model instantiation, AST parsing, reflection — in `try/catch` and **degrade to a
  safe default** (`{type: object}`, an empty array, `null`), never rethrow.
- Inference strategies fill gaps **non-destructively** with `??=`. Only
  `ExtractAttributes` (which runs last) assigns unconditionally, because explicit
  attributes are meant to win.
- New extraction code is guilty until proven safe: assume the input is malformed.

## Configuration & integrations

- Read runtime behaviour from `config('documentator.*')` with a sensible default
  baked into the call: `config('documentator.generate_examples', true)`.
- Optional integrations (e.g. `spatie/laravel-data`) must be **guarded with
  `class_exists()`** and be a complete no-op when absent — they are `suggest`,
  never `require`.

## Tests

- Pest, under `tests/Feature/`. Define throwaway controllers / FormRequests /
  Resources / models / Data objects at the top of the test file.
- Exercise extraction through **real `[Controller::class, 'method']` routes** —
  closure routes skip the reflection-based strategies.
- Assert on the generated OpenAPI array (`app(Documentator::class)->toOpenApi()`),
  not on internal state, so tests describe observable behaviour.
- Every behaviour change ships with a test.

## Security

- All spec-derived strings rendered in the built-in UI go through the `esc()`
  helper before `innerHTML`; Markdown goes through `block()`/`inline()` which
  escape first. Keep that order — never interpolate untrusted strings into HTML.
- Pinned, versioned asset URLs only (no unversioned "latest" CDN tags).
