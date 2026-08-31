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

## `orders` renommée `yango_orders` ; les commandes boutique sont préfixées `shop_`
`orders` portait les courses Yango terminées (MCD : `trip`), nommées ainsi pour coller à l'API Fleet. La boutique introduisant ses propres commandes, la table des courses est devenue `yango_orders` (modèle `YangoOrder`, enum `YangoOrderStatus`, relation `Driver::yangoOrders()`), et les commandes boutique sont `shop_orders` / `shop_order_items`. Ne pas reprendre le mot « order » nu pour l'une ou l'autre notion.

`vehicles.vehicle_model_id` (nullable) rapproche le véhicule du référentiel boutique. `brand` et `model` restent les chaînes libres de Yango et font foi : le lien est un rapprochement au mieux, jamais saisi à la main, nul si le catalogue ignore ce modèle.

## Le schéma est en ULID de bout en bout, `users` compris

`users.id` a longtemps été la seule clé auto-incrémentée du schéma ; elle est passée en ULID (`HasUlids` sur le modèle). Toute colonne qui référence un agent est donc un `foreignUlid` : `stock_movements.user_id`, `audit_logs.user_id`, `challenges.approved_by` / `created_by`, `challenge_winners.credited_by`, ainsi que `sessions.user_id`.

Deux exceptions volontaires demeurent, à ne pas « corriger » :

- `roles.id` et `permissions.id` restent des entiers auto-incrémentés — spatie/laravel-permission les gère lui-même et seule sa clé de morph a bougé (`config/permission.php` : `model_morph_key => 'model_uuid'`, colonnes `model_has_roles.model_uuid` / `model_has_permissions.model_uuid` en `ulid`).
- Les tables pivot de spatie portent une clé primaire composite incluant cette colonne : la retyper impose de reconstruire la clé et l'index.
