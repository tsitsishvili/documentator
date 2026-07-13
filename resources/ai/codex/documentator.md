# Documentator (tsitsishvili/documentator)

Auto-inferred, interactive **OpenAPI 3.1** API documentation for Laravel. It reads
your existing code — routes, FormRequests, inline validation, API Resources and
return types — and generates the docs; PHP attributes refine anything.

**Golden rule: inference first, attributes last.** Write idiomatic, typed Laravel
and most docs appear on their own. Add an attribute only to fix or add what
inference can't see — attributes always win.

To make an endpoint document well (no annotations needed):

- Prefer a `[Controller::class, 'method']` route for controller/class metadata;
  typed closures still support parameter, attribute, and return-expression inference.
- Add a docblock: **first line = summary**, the rest = description.
- Type-hint a `FormRequest` (its `rules()` become body params; on **GET/HEAD** they
  become **query** params) — or inline `$request->validate([...])`.
- Give the method a **return type**: an API `Resource`, `ResourceCollection`,
  Eloquent model, or `spatie/laravel-data` object → response schema is inferred.
- Status follows the verb (`POST → 201`, `DELETE → 204`); errors are added
  from auth, validation, model binding, `abort*`, authorization, and HTTP exceptions.

Override only the gaps with attributes from `Tsitsishvili\Documentator\Attributes`:

```php
use Tsitsishvili\Documentator\Attributes\{Summary, Group, QueryParam, Response, Authenticated, Hidden};

#[Group('Orders')]
#[Authenticated]
#[QueryParam('include', description: 'Comma-separated relations to embed.')]
#[Response(status: 201, resource: OrderResource::class, description: 'The created order.')]
public function store(StoreOrderRequest $request): OrderResource { /* ... */ }
```

Attributes include `#[Summary]`, `#[Description]`, `#[Group]`,
`#[TagDescription]`, `#[OperationId]`, `#[PathParam]`, `#[QueryParam]`,
`#[HeaderParam]`, `#[CookieParam]`, `#[BodyParam]`, `#[RequestMediaType]`,
`#[Response]`, `#[ResponseHeader]`, `#[Authenticated]`, `#[Server]`,
`#[Hidden]`, `#[Deprecated]`, `#[SchemaName]`, `#[UsesModel]`.

Operational notes:

- Docs are **disabled by default** — set `DOCUMENTATOR_ENABLED=true`. UI at `/docs`,
  spec at `/docs/openapi.json`. Only routes matching `documentator.routes.match`
  (default `api/*`) are documented.
- Verify with `php artisan documentator:check` (audits quality, validates the
  OpenAPI shape) — run it in CI. Use
  `php artisan documentator:check --against=openapi.json` to catch committed-spec
  drift, or add `--fail-on=breaking` to allow additive drift. Use
  `documentator:explain METHOD URI` to trace inference. Also:
  `documentator:generate` (cache), `documentator:export`, `documentator:postman`.

For the full how-to (rich validation-rule mapping, pagination, sections, the
complete attribute reference), see the `documentator-api-docs` skill at
`.claude/skills/documentator-api-docs/SKILL.md`.
