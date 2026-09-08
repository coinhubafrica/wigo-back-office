---
paths:
  - 'app/Services/History/**,app/Http/Controllers/Api/V1/HistoryController.php,app/Http/Resources/HistoryEntryPayload.php,app/Enums/HistoryKind.php,database/migrations/*driver_history_view*'
---

# Enums Migrations

## Le fil d'activité est une vue SQL, pas des lignes écrites dans `transactions`
`GET /api/v1/history` lit la vue `driver_history`, qui unionne quatre sources (transactions type=recharge, shop_orders, cnps_declarations, challenge_tickets agrégés par jour, challenge_winners crédités). Rien n'est écrit : les lignes déjà en base apparaissent sans reprise.

Pourquoi pas des lignes miroir dans `transactions`, malgré le commentaire de sa migration qui nomme « le fil d'activité du mobile » : sa `reference` est unique et sert de `client_reference` à Wave (`RechargeService::settleFromWebhook`) ; `provider` est non nul et ne connaît que Wave|Yango, alors qu'une commande n'a pas de fournisseur ; un ticket n'est pas de l'argent (`amount` unsignedInteger, `currency` XOF). Le commentaire conditionne d'ailleurs la bascule à une migration de données décidée — une double écriture n'en est pas une.

Deux objections à la vue ont été TESTÉES puis écartées, ne pas les ressortir sans refaire le banc :
- « le LIMIT ne traverse pas l'UNION » : mesuré à 20 000 lignes, 76 ms par la vue contre 73 ms par une union à prédicat poussé. 3 ms.
- « MySQL et SQLite divergent » : c'était un `CAST(date AS DATETIME)` fautif, qui rend l'entier 2026 en SQLite. Utiliser `datetime(...)` côté SQLite. Un test l'épingle (« dates a ticket to its own day »).

Pièges de la migration : `challenge_tickets` et `challenge_winners` n'ont aucun index sur `driver_id` seul, MySQL adosse donc la clé étrangère à l'index composite du fil et refuse de le supprimer (SQLSTATE HY000 1553) — `down()` rend d'abord un index à la contrainte. Les deux sens sont idempotents.

Piège du contrôleur : figer `nextCursor()`/`previousCursor()` AVANT de rédiger les lignes. `CursorPaginator` recalcule le curseur depuis le dernier élément de sa collection en y cherchant `occurred_at` et `id` ; une ligne rédigée ne les porte plus, le curseur sortait `occurred_at: null` et la pagination bouclait sans fin.

La vue porte l'ordre, les montants, `sign` et `unit` ; le texte français reste en PHP (`sublabel` exige une jointure 1-N sur `shop_order_items`). `amount.sign` vaut 0 pour une cotisation CNPS (l'argent est parti du compte Wave du conducteur, jamais du nôtre) et pour une commande annulée : l'application se règle sur `sign` seul pour la couleur.
