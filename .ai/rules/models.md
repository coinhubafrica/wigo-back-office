---
paths:
  - app/Models/Vehicle.php
  - app/Models/Product.php
---

# Models

## Vehicles are Fleet-sourced; no assignment history is kept
`drivers` and `vehicles` are both synchronised from Yango and each carries a `yango_id` used as the reconciliation key. Assignment is owned by Yango, never entered by hand in the back-office.

One row per vehicle: reassignment moves `driver_id` and flips `is_active`. There is NO assignment history — `assigned_from` / `assigned_to` were dropped even though mcd.mmd lists them ("affectation datée"), because nothing reads them: the mobile contract exposes only make/model/color/plate, and an in-place `driver_id` update overwrites any period they would record.

Do not re-add those columns, and do not introduce a `vehicle_assignments` table: assignment history is explicitly out of scope. The current assignment is whatever Yango last reported.

`Driver::vehicle()` resolves the current vehicle via `is_active` + `latestOfMany()`; the latter only guards against a transient double-active state mid-sync.

## Vehicles are Fleet-sourced; no assignment history is kept
`drivers` and `vehicles` are both synchronised from Yango and each carries a `yango_id` used as the reconciliation key. Assignment is owned by Yango, never entered by hand in the back-office.

One row per vehicle: reassignment moves `driver_id` and flips `is_active`. There is NO assignment history — `assigned_from` / `assigned_to` were dropped even though mcd.mmd lists them ("affectation datée"), because nothing reads them: the mobile contract exposes only make/model/color/plate, and an in-place `driver_id` update overwrites any period they would record. Do not re-add those columns, and do not introduce a `vehicle_assignments` table: assignment history is explicitly out of scope.

Both tables carry their own `last_sync_at` (nullable), stamped on each successful Fleet reconciliation. Vehicles get their own because the park can hold unassigned vehicles that sync independently of any driver. A null or stale value means Yango is no longer reporting that record — the sync job should surface it rather than silently skip it.

`Driver::vehicle()` resolves the current vehicle via `is_active` + `latestOfMany()`; the latter only guards against a transient double-active state mid-sync.

## Compatibilité pièce/véhicule : hiérarchie, pas de table de compatibilité
Le MCD prévoit `product_compatibility` (n-n pièce ↔ marque/modèle) avec son propre `model_price`. On a retenu la hiérarchie `vehicle_brands > vehicle_models > products` : une pièce appartient à au plus un modèle et porte un seul `unit_price`. Le prototype le disait déjà — `AM-DZ-AV1`, `DF-DZ-400` : le modèle est dans la référence.

`products.vehicle_model_id` est nullable = pièce universelle (huile, ampoules), visible quel que soit le véhicule. Conséquence assumée : une même pièce montée sur deux modèles fait deux lignes, deux références, deux stocks.

Ne pas réintroduire la table pivot ni `model_price`. La carte « Marques & modèles » du prototype se dérive de `VehicleBrand::with('vehicleModels')`.
