---
paths:
  - app/Livewire/Challenges/Show.php
---

# Livewire Challenges

## Le classement des participants reste en SQL, jamais le parc en mémoire
`Show::rankingQuery()` sélectionne les participants (whereHas courses terminées sur la période, ou tickets pour une tombola) avec `period_orders`/`period_tickets` en `selectSub`, et `rankedQuery()` numérote par `row_number()` **avant** la recherche et le filtre — le rang affiché est celui de l'ensemble. `frozenPoolRows()` agrège par porteur (`GROUP BY driver_id`, LIMIT 8) puis charge les huit conducteurs.

Ne pas revenir à `Driver::query()->withCount()->get()` filtré en PHP : c'était le parc entier hydraté quatre fois par rendu, et la page tombait en 504 en production. `eligibleCount()`/`ticketCount()` sont mémoïsés par rendu (propriétés privées, non sérialisées).

Pièges : `rank` est un mot réservé MySQL (colonne nommée `place`) ; `->count()` sur une requête ordonnée par alias casse — `rankingQuery()` est donc sans ordre. Les deux compteurs s'appuient sur `yango_orders (driver_id, status, completed_at)` et `(status, completed_at, driver_id)` (migration `reindex_yango_orders_for_period_aggregates`).
