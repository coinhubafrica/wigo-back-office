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

## The fiche CNPS panel is real now; trips and Yango balance are still placeholders
An earlier rule recorded that the fiche 360° showed courses/semaine, solde Yango and CNPS as "—" placeholders because the data did not exist. CNPS is no longer one of them: the `cnps_declarations` / `cnps_references` tables exist, so the "CNPS ce mois" stat card and a full contributions panel (reference amount, current month with progress, twelve months of history with each payment) are live.

The panel calls `CnpsStatementPayload::build()` — the same builder the mobile `GET /api/v1/cnps` uses. Keep it that way: an agent and a driver looking at the same month must never see two different totals, and duplicating the maths in the Livewire component is how that drift starts.

Nothing on the panel validates anything. Declarations carry no status by design (see .ai/rules/cnps.md); a test asserts the fiche renders no Valider/Rejeter control.

Still placeholders, still waiting on Fleet trip sync: courses de la semaine and solde Yango. Don't fake either.
