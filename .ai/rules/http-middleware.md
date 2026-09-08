---
paths:
  - 'app/Enums/DriverStatus.php,app/Services/Yango/**,app/Http/Middleware/EnsureDriverIsActive.php'
---

# Http Middleware

## DriverStatus est le work_status de Yango ; il n'y a plus de suspension locale
`DriverStatus` porte les valeurs de l'API Fleet : `working` / `not_working` / `fired`. Un seul vocabulaire, celui du parc — Yango tient le parc, deux vocabulaires finissaient par diverger.

À ne pas défaire :

- **La passe écrit `status` à chaque tour** (`YangoSyncService::syncDriver()`, depuis `driver_profile.work_status`). L'ancienne règle « le statut n'est jamais réécrit » est **annulée** : elle protégeait une suspension back-office qui n'existe plus.
- **Un `work_status` absent ou inconnu ne réécrit rien** (`DriverStatus::fromYango()` rend `null`) — même principe que le solde : `null` n'est pas un statut. Un conducteur créé sans statut lisible naît `NotWorking`, jamais `Working`.
- **`YangoProfileShape` traduit `profile.work_status` (v2) vers `driver_profile.work_status` (v1).** Sans cette ligne, un conducteur rapatrié nommément par `YangoDriverResolver` n'aurait jamais de statut.
- **Seul `fired` ferme l'écriture mobile** (`DriverStatus::blocksMobileWrites()`, `Driver::cannotWriteFromMobile()`, middleware `driver.active`). `not_working` est un état ordinaire : un conducteur qui ne roule pas aujourd'hui garde boutique, recharges et cotisations. Ne pas y ajouter `not_working` — ce serait couper des conducteurs qui n'ont rien fait.
- **Le contrat mobile garde son vocabulaire** : `DriverResource` publie `DriverStatus::wireValue()` (`working`→`active`, `not_working`→`dormant`, `fired`→`suspended`), pour qu'un changement côté parc n'oblige pas à livrer une version mobile le même jour.

La suspension back-office est **supprimée** : plus de `suspension_reason`, de `Permission::DriversSuspend`, d'actions d'audit `driver.suspended`/`driver.reactivated`, de `SystemMessageEvent` associés, ni de réglage `support.suspended_drivers_may_write`. Couper un conducteur se décide sur la plateforme Yango et nous revient par `fired`. Le module Chauffeurs n'a donc plus aucun geste journalisé. Ne pas les réintroduire sans demande explicite.
