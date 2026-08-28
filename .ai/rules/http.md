---
paths:
  - 'app/Http/**'
---

# Http

## Generated OpenAPI docs are the contract; Scramble infers them from code
`dedoc/scramble` generates the OpenAPI 3.1 document from the code itself — routes, Form Requests, API Resources, enums — with no annotation classes. The generated spec IS the source of truth consumed by the WiGO PRO mobile app. The handoff `openapi.yaml` was only a starting point and will legitimately drift; do not treat it as authoritative and do not add tooling that enforces it.

Consequences for writing endpoints:
- Accuracy comes from real types. Type Form Request rules, return typed Resources, and keep migration columns nullable-correct — Scramble reads all of it. A wrong type is now a wrong published contract.
- The first PHPDoc line on a controller method becomes the operation summary; the rest becomes its description.
- To document a value the inferrer cannot see, put PHPDoc on the array key inside `toArray()`: `@var 'active'|'suspended'` emits an enum, `@example` emits an example. Needed wherever a Resource emits `$enum->value`, which otherwise types as a bare string.
- Security is derived from `auth`/`auth:*` middleware (`security_strategy` in config/scramble.php): unauthenticated routes are marked public, protected ones get bearer + a documented 401.
- `api_path` documents `api/v1` only and excludes `api/webhooks` — the Wave callback is server-to-server, not part of the mobile contract.

Regenerate with `composer docs` (`php artisan scramble:export` → `openapi.json`, committed so changes surface as a diff in review). `/docs/api` is open in local; elsewhere it needs `?token=` matching `API_DOCS_TOKEN` via the `viewApiDocs` gate. tests/Feature/Docs/ApiDocumentationTest.php guards generation, route coverage and that gate.

## Generated OpenAPI docs are the contract; Scramble infers them from code
`dedoc/scramble` generates the OpenAPI 3.1 document from the code itself — routes, Form Requests, API Resources, enums — with no annotation classes. The generated spec IS the source of truth consumed by the WiGO PRO mobile app. The handoff `openapi.yaml` was only a starting point and will legitimately drift; do not treat it as authoritative and do not add tooling that enforces it.

Consequences for writing endpoints:
- Accuracy comes from real types. Type Form Request rules, return typed Resources, and keep migration columns nullable-correct — Scramble reads all of it. A wrong type is now a wrong published contract.
- The first PHPDoc line on a controller method becomes the operation summary; the rest becomes its description.
- To document a value the inferrer cannot see, put PHPDoc on the array key inside `toArray()`: `@var 'active'|'suspended'` emits an enum, `@example` emits an example. Needed wherever a Resource emits `$enum->value`, which otherwise types as a bare string.
- Scramble cannot infer a conditionally-present key. Add an explicit `@response array{...}` with `key?:` on the method, or the contract will list an optional field as required (see AuthController::requestOtp).
- Security is derived from `auth`/`auth:*` middleware (`security_strategy` in config/scramble.php): unauthenticated routes are marked public, protected ones get bearer + a documented 401.
- `api_path` documents `api/v1` only and excludes `api/webhooks` — the Wave callback is server-to-server, not part of the mobile contract.

Regenerate with `composer docs` (`php artisan scramble:export` → `openapi.json`, committed so changes surface as a diff in review).

Access to `/docs/api` has two levers. `API_DOCS_ENABLED` (`wigo.docs.enabled`) is the master switch, applied by EnsureApiDocsAreEnabled, which must stay ahead of Scramble's RestrictedDocsAccess in the config middleware list — Scramble short-circuits on the local environment before consulting any gate, so the switch would otherwise be ignored in local. Once enabled, local is open and other environments require `?token=` matching `API_DOCS_TOKEN` (`wigo.docs.token`) via the `viewApiDocs` gate; no token configured means closed. tests/Feature/Docs/ApiDocumentationTest.php guards generation, route coverage and both levers.

## One response envelope for api/v1, and Scramble can't infer it
Every `api/v1` response uses one envelope: success `{message, data}` (+ `meta`/`links` when paginated), error `{message, errors}`. Successes come from the `ApiResponses` trait on `App\Http\Controllers\Controller`; errors are shaped centrally by the `$exceptions->render()` callback in `bootstrap/app.php`, so controllers never build an error body. That callback returns `null` for non-`api/*` requests — the back-office keeps Laravel's own error handling — and passes `HttpResponseException` straight through, because the `otp` rate limiter supplies its own French 429 response.

Resources keep `public static $wrap = null`: the trait owns the envelope, so resource-level wrapping would nest `data` twice.

Scramble cannot infer through the trait — any indirection makes it document the trait's internals (an empty-key object, or `apiMessage()`'s `string`). Two ways to keep the published contract honest, both required:
- Simple resource/collection responses: annotate the method with `#[ApiResponse(FooResource::class, collection: true, paginated: true)]`. `App\Support\Scramble\WrapApiEnvelope` (registered in `config/scramble.php` `extensions`) turns that into the enveloped schema while Scramble still resolves the resource itself, so named schemas and enums survive.
- Composite payloads: declare the shape with `@response array{...}` on the method. Do NOT make such a payload a `JsonResource` with `@mixin Model` — Scramble then publishes every DB column (`draw_seed`, `min_rating_enabled`…) instead of the real contract. `DriverChallengePayload` is a plain builder class for exactly this reason.

After any response change run `composer docs` and review the `openapi.json` diff; `tests/Feature/Docs/ApiDocumentationTest.php` asserts route coverage and that `DriverResource.status` still publishes its enum.
