---
paths:
  - 'database/seeders/**'
---

# Seeders

## DriverSeeder covers the states endpoints branch on; OTP codes are returned outside production
`DriverSeeder` seeds seven drivers on fixed, memorable phone numbers — a stable fixture set for the mobile team. Each one exists to make a specific branch reachable: nominal with vehicle (+2250717738299), suspended with reason (…002), dormant (…003), terms not accepted (…004), no `yango_id` (…005), OTP locked (…006), no vehicle (…007). When you add a branch to an auth endpoint, add the driver that reaches it.

The seeder is idempotent, keyed on phone: it updates in place and only creates a vehicle whose plate is absent. Re-running must never duplicate rows. It is skipped entirely when `app()->isProduction()`.

`POST /auth/otp/request` returns the plain `code` in its response when `wigo.otp.expose_code` (`WIGO_OTP_EXPOSE_CODE`) is on — enabled in phpunit.xml and local `.env`, so tests and manual calls log in with two requests instead of reading the code out of the log. This is a complete OTP bypass: `OtpService::exposesCode()` returns false whenever the app is in production regardless of config, and a test asserts that. Never remove that guard, and never default the env var to true.

Because that key is only sometimes present, the contract lists `code` under `properties` but leaves it out of `required` in `docs/api/paths/auth.yaml`. Publishing it as required would tell the mobile team to expect a field production never sends.
