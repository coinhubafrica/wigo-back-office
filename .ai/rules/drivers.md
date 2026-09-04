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

## Profile photos are not moderated — the driver's upload lands on the fiche as-is
An earlier rule recorded photo moderation as real (`drivers.photo_status`, a pending banner, `approvePhoto()`/`rejectPhoto()`). That was dropped on explicit instruction: the driver changes their photo from the mobile app and the profile is updated, nothing more. The `photo_status` column, the `DriverPhotoStatus` enum, `hasPhotoPendingModeration()`, the banner and both actions are gone; a test asserts the fiche renders no approve/reject control. Do not reintroduce them without an explicit ask.

The file lives on the private `local` disk (`driver-photos/{driver}/…`) — a portrait is personal data, never behind a guessable public URL. The fiche cannot point at it directly, so the avatar block reads `bo.drivers.photo` (BackOffice\DriverPhotoController, session + `module.drivers` permission) and falls back to `initials()` when `photo_url` is null. Mobile reads the same file through a temporary signed route (`api.v1.photo`), built by `DriverResource::photoUrl()`; the column stores a path, so never expose it raw.

## The drivers list CNPS column batches two queries per page — never one per row
The index table now carries ID/téléphone (`yango_id` + `phone`), n° de permis, solde Yango and a CNPS badge. There is no selfie/photo column — photo moderation is gone (see the photo rule above); don't add one back from the prototype mockup.

CNPS state is computed, never stored (see .ai/rules/cnps.md). `Index::cnpsStatuses()` resolves it for the whole page in two aggregate queries — a grouped `sum(declared_amount)` for the current period and the in-force `cnps_references` row — both scoped to the 20 paginated driver ids, then defers the verdict to `CnpsStatementService::statusFor()`. Calling the service per driver instead is a 40+ query render; a full page currently costs 9 queries total. Keep the maths in the service so an agent and a driver never read two different totals.

Columns are deliberately not sortable. The prototype header shows sort arrows, but `x-th` has no sort variant and solde Yango/CNPS would need aggregate-subquery joins to sort in SQL. Explicit instruction: render the columns, skip the sorting.

Courses de la semaine is still the only placeholder left, still waiting on Fleet trip sync. Don't fake it.

## The fiche is a resume plus four tabs; only the open tab is queried
Layout: one header panel (identity, yango_id, phone, permis, vehicle line, suspend/reactivate), three KPI cards (solde Yango, CNPS ce mois, requêtes ouvertes), then a single `x-panel flush` holding the activity as tabs — Requêtes, Commandes boutique, Recharges Yango, Cotisations CNPS (RSTI).

There is no `x-tabs` component: the tabs are `x-chip-filter` chips in the panel's `actions` slot (explicit instruction — don't build a tabs component for this). The panel title tracks the active tab so the section keeps its `aria-labelledby` name. `Show::tabs()` is the single source of the tab list and its order; `selectTab()` ignores an unknown key and `mount()` falls back to Requêtes, so a stale `?tab=` in a shared link cannot render an empty panel.

`tab` is a `#[Url]` prop — an agent shares the link to the tab they are looking at. Consequence for tests: the CNPS statement is NOT on the page at load. A test asserting on the statement must request `route('bo.drivers.show', [$driver, 'tab' => 'cnps'])` or `call('selectTab', Show::TAB_CNPS)`; several CnpsTest cases had to be pointed at the tab. Watch for tests that pass vacuously off the KPI card instead of the panel.

`Show::rowsForActiveTab()` queries only the open tab — loading all four each render would pay for three lists nobody reads. Recharges Yango is `Transaction` filtered by `scopeRecharges` (NOT `YangoOrder`, which is a trip); requêtes count on the KPI card uses `scopeLive`. `Driver::supportRequests()` was added for this.

Dropped on explicit instruction, do not reintroduce from the prototype mockup: the selfie/photo-moderation banner, and any account activation/deactivation control (suspend/reactivate is the only account action — activation is not ours). Courses de la semaine has no card at all now rather than a second placeholder dash; still waiting on Fleet trip sync.

The index row is fully clickable via `after:absolute after:inset-0` on the name's `<a>` plus `relative` on the `<tr>` — one tab stop, real link, `wire:navigate` intact. Never an `onclick` on the `<tr>`.

## The fiche activity is four panels side by side, not tabs — five rows each
Supersedes the rule above it ("a resume plus four tabs"). Tabs were built, then dropped on explicit instruction: the four activity lists are now `x-panel flush` cards in a `grid items-start gap-4 xl:grid-cols-2` — Requêtes + Commandes boutique on the first row, Recharges Yango + Cotisations CNPS (RSTI) on the second. Everything is visible at once; an agent on the phone with a driver should not click to see that they have an order in flight *and* a failed recharge.

Gone with the tabs: the `tab` `#[Url]` prop, `Show::tabs()`, `selectTab()`, the `TAB_*` constants, `rowsForActiveTab()`. Do not reintroduce them — and drop any `['tab' => …]` route param in tests, it is a dead no-op now (five in CnpsTest were removed).

`items-start` on the grid is load-bearing: without it grid rows stretch to the tallest cell and the short panel trails a few hundred px of dead white.

Each panel is capped at `Show::ROWS_PER_PANEL` (5) and carries a "Tout voir" link to its own module (`bo.support-requests`, `bo.shop.orders`, `bo.recharges`, `bo.cnps`) — the fiche is an overview, the module is where history gets unrolled. This is what keeps four lists on one page tenable.

The CNPS panel asks `CnpsStatementPayload::build($driver, $service, ROWS_PER_PANEL + 1)`, so the fiche shows the current month plus five, while the mobile API keeps the default 13. Same builder, same maths, different depth — that is the only permitted divergence. A test now guards the 13-month default (nothing did before), and the fiche test asserts the shallower window explicitly. Careful writing those: CnpsTest freezes time to 2026-08-29, so "current" is Août 2026, the fiche window ends Mars 2026 and the mobile one Août 2025.

All four lists load every render (4 queries + the statement) — that is the accepted cost of showing everything, and the five-row cap is what bounds it.
