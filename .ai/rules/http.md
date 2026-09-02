---
paths:
  - 'app/Http/**'
---

# Http

## The OpenAPI contract is hand-written in `docs/api/`
The contract is no longer inferred from code: it is hand-written, split under `docs/api/` (one path file per tag, one file per schema), and assembled into `openapi.json` by `composer docs`. That `openapi.json` is a **generated artefact** — never hand-edit it. It stays committed so a contract change reads as a diff in review, and `composer test` runs `docs:bundle --check`, which fails on a stale bundle.

Consequences for writing an endpoint:
- Adding a route means adding its operation to `docs/api/paths/*.yaml`. `tests/Feature/Docs/ApiDocumentationTest.php` compares `api/v1` routes against the contract **both ways**: an undocumented route fails, and so does a documented path with no route.
- Accuracy no longer comes from PHP types but from tests: `tests/Feature/Docs/ApiContractTest.php` validates real responses against the published schemas. A field added, removed or retyped in a Resource without updating the YAML fails with the offending JSON pointer.
- Middleware is no longer invisible: the `Idempotency-Key` header and the 409 are components (`components/parameters/`, `components/responses/`) referenced by the writes that carry them, and the test derives that list from `gatherMiddleware()`.
- The contract covers `api/v1` only; the Wave webhook is server-to-server and a test asserts it never appears.

Access to `/docs/api` has two independent levers. `API_DOCS_ENABLED` (`wigo.docs.enabled`) is the master switch, applied by `EnsureApiDocsAreEnabled` — with no exception, local included. Then `EnsureApiDocsAreAuthorized` opens local and elsewhere requires `?token=` matching `API_DOCS_TOKEN` (`wigo.docs.token`) via the `viewApiDocs` gate; no token configured means closed. Both levers are covered by `tests/Feature/Docs/ApiDocumentationTest.php`.

## One response envelope for api/v1, written out in the contract
Every `api/v1` response uses one envelope: success `{message, data}` (+ `meta`/`links` when paginated), error `{message, errors}`. Successes come from the `ApiResponses` trait on `App\Http\Controllers\Controller`; errors are shaped centrally by the `$exceptions->render()` callback in `bootstrap/app.php`, so controllers never build an error body. That callback returns `null` for non-`api/*` requests — the back-office keeps Laravel's own error handling — and passes `HttpResponseException` straight through, because the `otp` rate limiter supplies its own French 429 response.

Resources keep `public static $wrap = null`: the trait owns the envelope, so resource-level wrapping would nest `data` twice.

The envelope is not inferred from anywhere: it is written out explicitly in each operation's response schema in `docs/api/paths/*.yaml` (`message` + `data`, plus `meta`/`links` when the response is paginated). `tests/Feature/Docs/ApiContractTest.php` fails if a real response drifts from what is published.

A composite payload — one that assembles several sources rather than projecting a model — stays a plain builder class, not a `JsonResource` with `@mixin Model`: `DriverChallengePayload` and `CnpsStatementPayload` exist for that reason, and their shape lives in the YAML like any other.

After any response change run `composer docs` and review the `openapi.json` diff; `composer test` refuses a stale bundle.
