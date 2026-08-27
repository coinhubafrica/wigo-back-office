---
paths:
  - 'app/Livewire/Drivers/**'
---

# Drivers

## Drivers module: DriverStatus::Dormant renders as "En attente"; fiche omits unbuilt-data panels
The prototype's driver status filter is "actif / suspendu / en attente" — a 3-state UI, matching the MCD's `active/suspended/dormant` exactly. `DriverStatus::Dormant->label()` returns "En attente" for BO display; the enum's wire value (`dormant`) is unchanged since it's shared with the mobile API contract.

The fiche 360° (`Show.php`/`show.blade.php`) intentionally omits: courses/semaine, solde Yango, CNPS status — these render as "—" placeholders (grid of 3 stat cards) — and the "Requêtes du conducteur" panel is dropped entirely. All three depend on data that doesn't exist yet (Fleet trip sync, CNPS declarations, support tickets). Add them back only once those modules/tables exist; don't fake the data or the panel in the meantime.

Photo moderation is real: `drivers.photo_status` (nullable, `DriverPhotoStatus` enum: pending/approved/rejected) drives a banner shown only when `hasPhotoPendingModeration()` is true. `approvePhoto()`/`rejectPhoto()` just flip the enum — no notification dispatch to mobile yet (that's a mobile-API concern, not in scope here).

Suspend/reactivate is real and audited only by `suspension_reason` on the row (no audit log per earlier explicit instruction — "not need for audit log for now"). The reactivate button uses `wire:confirm`, which opens a native browser dialog — this blocks CDP-driven browser automation (Claude-in-Chrome), so verify reactivate via Livewire component tests, not live click-through.
