# Built-in UI Code Map

This document maps the built-in Documentator explorer code by responsibility.
Use it when you need to answer: "where does this happen?" or "which file should I edit?"

## Big Picture

The built-in explorer is a no-build-step browser UI served from `resources/ui`.
Laravel serves the files directly through the package asset route.

Request flow:

1. `resources/views/docs.blade.php` renders the HTML shell and `window.__DOCUMENTATOR__` config.
2. `resources/ui/app.js` loads the UI modules.
3. `resources/ui/core.js` fetches the OpenAPI document, builds endpoint state, renders the explorer, and handles the
   live request console.
4. `resources/ui/snippets.js` renders and updates code snippets for cURL, PHP, JS, TypeScript, Python, Go, Ruby, Java,
   C#, and HTTPie.
5. `resources/ui/app.css` styles the entire explorer.

## Files

| File                                        | Purpose                                                                                                                                 |
|---------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| `resources/views/docs.blade.php`            | Laravel Blade shell for the built-in UI. Defines config and loads `app.css` plus `app.js`.                                              |
| `resources/ui/app.js`                       | Tiny entrypoint. Imports `core.js` and `snippets.js`, then starts the app.                                                              |
| `resources/ui/core.js`                      | Main explorer application: OpenAPI loading, state, navigation, docs rendering, auth modal, request form, live send, response rendering. |
| `resources/ui/snippets.js`                  | Request snippet UI and language-specific code generation.                                                                               |
| `resources/ui/app.css`                      | All built-in explorer styles.                                                                                                           |
| `src/Http/Controllers/AssetController.php`  | Whitelists and serves UI assets.                                                                                                        |
| `src/DocumentatorServiceProvider.php`       | Registers docs, OpenAPI, section, and asset routes.                                                                                     |
| `dev/preview.html`                          | Standalone preview harness for the built-in UI. Serve it over HTTP.                                                                     |
| `dev/sample-openapi.json`                   | Sample OpenAPI document used by `dev/preview.html`.                                                                                     |
| `tests/Feature/DocsUiTest.php`              | PHP route/asset/shell tests for the docs UI.                                                                                            |
| `tests/Browser/built-in-ui.visual.spec.mjs` | Playwright coverage for layout, virtualization, filters, and mobile behavior.                                                           |

## How To Open The Preview

From the package root:

```bash
php -S localhost:8123 -t .
```

Then open:

```text
http://localhost:8123/dev/preview.html
```

Opening `http://localhost:8123/` returns 404 because the package root does not have an `index.html`.

## Core Module Map

`resources/ui/core.js` is organized with section comments. Search for these headings:

| Section                 | What Happens There                                                                                                                      |
|-------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| `helpers`               | Escaping, OpenAPI `$ref` resolving, schema type labels, markdown-ish text rendering, local storage helpers, URL/hash helpers.           |
| `data`                  | Converts `spec.paths` into `state.operations`, resolves sections, groups endpoints, and prepares server URLs.                           |
| `security`              | Reads OpenAPI security schemes, stores auth tokens, builds auth headers/query params.                                                   |
| `shell`                 | Renders the topbar, sidebar, document panel, console panel, modals, and wires global event handlers.                                    |
| `authorize modal`       | Builds and controls the auth token modal.                                                                                               |
| `health modal`          | Computes and renders documentation quality metrics.                                                                                     |
| `navigation`            | Filters, groups, virtualizes, and renders the sidebar endpoint list.                                                                    |
| `documentation surface` | Renders the selected endpoint docs: path, summary, params, request body, responses, headers, examples.                                  |
| `console`               | Builds the right-side request form, reads form values, handles repeatable fields/files/body JSON, and produces normalized request data. |
| `clipboard`             | Copy helper used by links, snippets, and responses.                                                                                     |
| `send`                  | Sends live requests with `fetch`, builds multipart/json bodies, applies same-origin cookie parameters, and renders response or error output. |
| `boot`                  | Fetches the OpenAPI JSON and starts rendering.                                                                                          |

## Common Change Finder

| If You Need To Change...              | Start Here                                                                                           |
|---------------------------------------|------------------------------------------------------------------------------------------------------|
| Initial app loading                   | `resources/ui/app.js`                                                                                |
| The Laravel docs shell HTML           | `resources/views/docs.blade.php`                                                                     |
| Which JS/CSS files Laravel can serve  | `src/Http/Controllers/AssetController.php` and `src/DocumentatorServiceProvider.php`                 |
| OpenAPI fetch or boot failure message | `resources/ui/core.js`, `boot` section                                                               |
| Endpoint list building                | `resources/ui/core.js`, `buildOperations`, `groupsFor`, `compareGroups`                              |
| Sidebar virtualization                | `resources/ui/core.js`, `navigation` section                                                         |
| Search/filter behavior                | `resources/ui/core.js`, `wireShell`, `renderNav`, `groupsFor`                                        |
| Topbar method counters                | `resources/ui/core.js`, `methodStats` and `wireShell`                                                |
| Section dropdown behavior             | `resources/ui/core.js`, `sectionFilterHtml` and `wireShell`                                          |
| Endpoint docs page                    | `resources/ui/core.js`, `renderDoc`, `rowsFromSchema`, `schemaSection`                               |
| Parameter/schema table rows           | `resources/ui/core.js`, `rowsFromSchema`                                                             |
| Response examples and headers         | `resources/ui/core.js`, `responseExample`, `responseHeadersHtml`, `exampleBlock`                     |
| Request console fields                | `resources/ui/core.js`, `renderConsole`, `parameterFieldControl`, `bodyFieldControl`                 |
| Global path parameter behavior        | `resources/ui/core.js`, `globalPathParameter`, `globalPathInputValue`, `globalPathFieldControl`      |
| Reading request form data             | `resources/ui/core.js`, `readForm`, `readBody`, `coerce`                                             |
| Sending live API requests             | `resources/ui/core.js`, `send`                                                                       |
| Response tree rendering               | `resources/ui/core.js`, `jsonNode`, `jsonTree`, `renderResponse`                                     |
| Request panel resizing                | `resources/ui/core.js`, `wireConsoleResize`                                                          |
| Auth modal and tokens                 | `resources/ui/core.js`, `authModalHtml`, `authToken`, `saveAuth`, `applyAuthHeader`                  |
| Documentation health modal            | `resources/ui/core.js`, `healthMetrics`, `openHealth`                                                |
| Snippet language tabs/dropdown        | `resources/ui/snippets.js`, `html`, `wire`, `onLangClick`, `onOtherChange`                           |
| Adding a snippet language             | `resources/ui/snippets.js`, add `buildX`, then update `GENERATORS`, `PRIMARY_LANGS` or `OTHER_LANGS` |
| TypeScript type generation            | `resources/ui/snippets.js`, `TypeScript types` section and `buildTypeScript`                         |
| Snippet syntax highlighting           | `resources/ui/snippets.js`, `highlightCode`                                                          |
| UI colors, spacing, responsive layout | `resources/ui/app.css`                                                                               |
| Browser UI regression tests           | `tests/Browser/built-in-ui.visual.spec.mjs`                                                          |
| Laravel docs asset tests              | `tests/Feature/DocsUiTest.php`                                                                       |

## State Shape

The main UI state lives in `resources/ui/core.js` near the top:

```js
state = {
    spec,
    operations,
    servers,
    sections,
    sectionLinks,
    sectionFilter,
    methodFilter,
    collapsedGroups,
    currentId,
    slugToId,
    navRows,
}
```

Important state transitions:

| Transition                                    | Function                        |
|-----------------------------------------------|---------------------------------|
| OpenAPI JSON becomes endpoint entries         | `buildOperations`               |
| URL hash selects endpoint                     | `applyHash`                     |
| Sidebar click selects endpoint                | `select`                        |
| Search/method/section changes rebuild sidebar | `renderNav`                     |
| Endpoint selection refreshes docs and console | `renderDoc` and `renderConsole` |
| Console form changes refresh snippets         | `snippets.update()`             |
| Send button reads form and calls API          | `readForm` then `send`          |

## Snippet Module Map

`resources/ui/snippets.js` is intentionally separate because code generation is dense.

| Area                                         | What It Does                                                                            |
|----------------------------------------------|-----------------------------------------------------------------------------------------|
| Formatting helpers                           | Shared string escaping, indentation, PHP/Python literals, Java text blocks.             |
| `buildCurl`, `buildLaravel`, `buildJs`, etc. | One generator per language. Each receives the normalized request object from `core.js`. |
| `TypeScript types`                           | Converts OpenAPI schemas into TypeScript declarations and response date hydration code. |
| `GENERATORS`                                 | Registry mapping language keys to labels and builder functions.                         |
| `PRIMARY_LANGS` and `OTHER_LANGS`            | Controls which languages are tabs and which live in the dropdown.                       |
| `html`                                       | Returns the snippet toolbar and code block markup.                                      |
| `wire`                                       | Attaches snippet tab/dropdown/copy events.                                              |
| `updateSnippet`                              | Rebuilds highlighted snippet code from the current request form.                        |

## Styling Map

All styles are in `resources/ui/app.css`.

Useful search terms:

| Search For                             | Area                                     |
|----------------------------------------|------------------------------------------|
| `.topbar`                              | Header, brand, method counters, actions. |
| `.layout`                              | Main page grid.                          |
| `.sidebar`, `.nav`                     | Sidebar and endpoint navigation.         |
| `.doc`, `.endpoint`                    | Main documentation surface.              |
| `.console`                             | Right-side request console.              |
| `.modal`, `.authmodal`, `.healthmodal` | Auth and health dialogs.                 |
| `.snippet`                             | Request snippets.                        |
| `.response`                            | Live response panel.                     |
| `@media`                               | Responsive behavior.                     |

## Tests To Run

When changing the built-in UI:

```bash
node --check resources/ui/app.js resources/ui/core.js resources/ui/snippets.js
./vendor/bin/pest tests/Feature/DocsUiTest.php
npm run test:browser
```

Run the broader suite before a release or when PHP extraction/OpenAPI behavior changes:

```bash
composer test
```

## Notes For Future Edits

- Keep `app.js` tiny. It should only load modules and start the app.
- Keep language generators in `snippets.js`, not `core.js`.
- Keep Laravel asset serving explicit. Add new UI files to both the route regex and `AssetController` whitelist.
- The UI has no bundler. Browser imports must work as plain files served from `/docs/assets/...`.
- All spec-derived strings rendered as HTML should pass through `esc`, `inline`, or `block`.
- If a change affects layout, update or add Playwright coverage in `tests/Browser/built-in-ui.visual.spec.mjs`.
