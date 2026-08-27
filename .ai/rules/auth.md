---
paths:
  - 'app/Services/Auth/**'
---

# Auth

## OTP state lives in otp_codes, not on drivers; lockout is derived
All OTP state is in the `otp_codes` table (one row per send: code_hash, channel, sent_at, expires_at, attempts, consumed_at, locked_until, request_ip). `drivers` carries NO otp_* columns.

This deviates from mcd.mmd / the handoff README, which specify "champs portés par drivers (pas de table otp_codes)". Chosen deliberately for the audit trail, and because inline fields allowed only one live code — a second send destroyed the first, so a driver whose SMS arrived out of order typed a dead code. Do not move this state back onto `drivers`.

Lockout is NOT a column: `OtpService::lockedUntil()` reads the most recent `locked_until` and treats a past value as unlocked, so a block expires on its own. Reaching `wigo.otp.max_attempts` stamps `locked_until` on every code in flight and consumes them.

Several codes may be usable at once — verify checks the submitted code against all of them (`scopeUsable`). A success consumes every code in flight. Failures increment the counter on all of them, so the threshold is per-driver, not per-code.

History is retained: `wigo.otp.retention_days` (30) with a daily `otp:prune-codes` scheduled task. Never delete codes on successful login.
