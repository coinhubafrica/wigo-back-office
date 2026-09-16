---
paths:
  - 'app/Services/Yango/**,app/Services/Challenges/DailyActivityRebuilder.php,app/Livewire/YangoSync/**'
---

# Livewire Yango Sync

## Le cumul journalier se répare sans Yango, et un téléphone en double ne tue plus la passe
Trois faits liés, découverts sur un écart mesuré en préproduction (2026-09-12 : 17 536 au rattrapage, 1 740 au tableau de bord).

1. **Les deux écrans ne comptent pas la même chose.** L'écran de rattrapage comptait toutes les courses, le tableau de bord ne compte que `status = complete`. Une journée porte volontiers autant d'annulées que de terminées (8 281 contre 9 255 ce jour-là). La couverture ventile donc par statut et affiche le cumul journalier en face, avec un signalement quand les deux divergent — c'est ce qui rend le trou visible.

2. **`YangoSyncService::syncDriver()` écarte un profil dont le téléphone est déjà porté par un autre `yango_id`.** L'adoption ne regarde que les lignes `yango_id IS NULL` ; sans cette garde, la création butait sur `drivers.phone` unique et la `PDOException` tuait le job entier. 76 des 96 `SyncYangoOrdersJob` en échec venaient de là. Départager deux profils qui revendiquent le même numéro n'est pas une décision automatique : on journalise et on écarte.

3. **Le recalcul du cumul est isolé par conducteur** (`YangoOrderSyncService::recordDays()`), parce qu'il tourne **après** que les courses sont écrites : une exception au milieu laissait les courses en base et le tableau de bord creux. `YangoOrderSyncResult::$activitiesFailed` mesure exactement cet écart.

Pour réparer, `DailyActivityRebuilder` + `RebuildDailyActivityJob` recomptent depuis `yango_orders` **sans appeler Yango** — une journée dont les courses sont déjà là ne doit pas coûter une boucle de curseur. Attention : `orders_total` est un cumul de carrière chaîné, donc `repairTotalsFrom()` doit courir depuis la **plus ancienne** journée réparée et une seule fois (l'écran ne le demande que sur `$days[0]`), et un rattrapage de période se fait dans l'ordre chronologique.
