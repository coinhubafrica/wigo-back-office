---
paths:
  - 'app/Services/Cnps/**'
---

# Cnps

## CNPS is declarative self-tracking: no status, no validation queue
A CNPS declaration records a payment the driver already made in Wave. We are a personal tracker, not an authority — the mobile screen says « Suivi personnel déclaratif. Seuls les états de la CNPS font foi. »

`cnps_declarations` deliberately has **no status column**: no pending/validated/rejected, no `rejection_reason`, no `validated_at`, and no back-office validation queue, even though mcd.mmd and the handoff `openapi.yaml` both list them. Do not add one back. Paid / partial / late are computed in `CnpsStatementService` from the declared sum against the reference in force, never stored.

**One row per payment, not per month.** A month is often settled in instalments, so `POST /cnps/declarations` never returns 409 on a period already declared — the handoff spec's 409 contradicts this and was dropped. `is_carry_over` / `source_period` are also omitted: settling August's shortfall in September is just `period=2026-08` with a September `payment_date`.

**The two tables have no foreign key between them.** Both hang off `driver`. The reference in force belongs to the *month*, not to a payment — two August payments share one, and a late payment still answers to its original month's reference. They meet only at read time, joined on `period`. `cnps_references` is append-only with `effective_from` so raising the amount in March leaves February judged at February's reference; never collapse it to a single mutable column on `drivers`.

**Proofs are private.** They are financial documents naming a person: stored on the `local` (private) disk under `cnps-proofs/{driver_id}/`, never the `public` disk, and the column holds a path, never a URL (a stored signed URL would bake in a dead signature). The download route is `signed`, but the signature is not authorisation — `CnpsController::proof()` also checks the declaration belongs to the authenticated driver, and a test asserts a valid signature from another driver still gets 403.

Reads stay open to a suspended driver (like `/me`); the two writes carry `driver.active`.

Testing note: `UploadedFile::fake()` marks the file as test-mode, so `getMimeType()` trusts the declared type and `mimes:` cannot be exercised. Proving a disguised file is rejected needs a real temp file wrapped in `new UploadedFile(...)`.
