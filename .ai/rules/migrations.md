---
paths:
  - 'database/migrations/**'
---

# Migrations

## Yango reference column is yango_id, not the MCD's yango_driver_id
`drivers.yango_id` (nullable, unique) holds the driver's Yango reference — the key used to reconcile Fleet API data (completed trips, wallet balance).

This is a deliberate deviation: mcd.mmd calls it `yango_driver_id`. The project chose the shorter name. Do not "fix" it back to match the MCD, and do not add a second column. The MCD remains the source of truth for the schema everywhere else.

Related Yango-side references keep their MCD names (`trips.yango_trip_id`, `drivers.yango_balance`). Never exposed on the wire — openapi.yaml has no Yango field.

## Yango reference columns are named yango_id, not the MCD's prefixed names
Both `drivers.yango_id` and `vehicles.yango_id` (nullable, unique) hold the Yango-side reference — the key used to reconcile Fleet API data (completed trips, wallet balance, vehicle assignment).

Deliberate deviation: mcd.mmd calls the driver one `yango_driver_id` and gives `vehicle` no Yango reference at all. The project uses a plain `yango_id` on each table. Do not "fix" these back to the MCD names and do not add duplicate columns. The MCD remains the source of truth for the schema everywhere else.

Both are nullable: a driver or vehicle not yet reconciled with the park simply yields no Fleet data. Neither is exposed on the wire — openapi.yaml has no Yango field.

`trips.yango_trip_id` keeps its MCD name (it is a foreign reference to a Yango trip, not the row's own identity).
