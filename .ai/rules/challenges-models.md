---
paths:
  - 'app/Services/Challenges/**,app/Models/DriverDailyActivity.php'
---

# Challenges Models

## `activity_date` se caste en `date:Y-m-d`, sinon `recordDay()` n'est pas rejouable
`DriverDailyActivity::$casts` doit garder `'activity_date' => 'date:Y-m-d'`, format explicite compris.

Sans le format, le cast `date` écrit « 2026-09-03 00:00:00 » dans une colonne `date`, et la clé de recherche de `DailyActivityService::recordDay()` (« 2026-09-03 ») ne retrouve pas la ligne qu'elle vient d'écrire : `updateOrCreate` insère alors un doublon et bute sur `UNIQUE (driver_id, activity_date)`.

Invisible tant que personne ne rejouait une journée — le seeder n'en rejouait aucune. `YangoOrderSyncService`, lui, rejoue : une passe de courses relancée sur la même journée passe forcément par ce chemin. Ne pas retirer le format, et garder `->format('Y-m-d')` (pas `toDateString()` sur un objet daté) dans la clé de `updateOrCreate`.
