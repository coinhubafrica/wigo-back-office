---
paths:
  - 'app/Livewire/YangoSync/**,app/Services/Yango/YangoDailyStatsRecorder.php,app/Models/YangoDailyStat.php,app/Livewire/Dashboard.php'
---

# Models Livewire

## Deux cumuls séparés, et c'est l'écart entre eux qui est l'outil
`yango_daily_stats` (une ligne **par journée**, tout le parc) et `driver_daily_activities` (une ligne **par conducteur et par journée**) descendent tous deux de `yango_orders`, mais par des chemins différents. Cette redondance est délibérée.

L'écran `bo.yango-sync` affiche les deux côte à côte et signale leur écart — témoin calculé au rendu, stocké nulle part. C'est lui qui a révélé le 2026-09-12 : 9 255 courses terminées pour 1 740 au tableau de bord, parce que `YangoOrderSyncService::recordDays()` avale les exceptions conducteur par conducteur alors que le cumul parc s'écrit d'un seul coup, hors de cette boucle. **Ne jamais alimenter l'un en sommant l'autre** : la comparaison deviendrait tautologique et l'écran perdrait son seul diagnostic.

Limite à connaître : l'écran voit un trou de *cumul*, pas un trou de *source*. Si les courses n'ont jamais été rapatriées, les deux côtés s'accordent sur un nombre faux et rien ne s'allume.

Le tableau de bord (`Dashboard::parkDailyTotals()`) lit `yango_daily_stats` et **pas** le cumul par conducteur : il ne demande que des totaux de parc, et sommer 80 615 lignes pour en tirer 77 nombres coûtait 206 ms, trois fois par rendu. La lecture est mémorisée dans une propriété — trois appelants (histogramme 7 jours, courbe 12 semaines, carte de la semaine) se partagent une seule requête.

Les **challenges ne lisent pas ces cumuls** : ils comptent des conducteurs *distincts* sur une période, ce qu'une somme par journée ne sait pas rendre, et les périodes à cheval sur une journée (2 des 10 challenges commencent à 14:00:55) gonflaient le compte de ~16 %. Leurs requêtes épinglent déjà `status` et sont rapides.

Le bouton « Recompter » d'une ligne passe `repairTotals: true`, contrairement au formulaire de période qui ne le passe qu'à `$days[0]` : une journée isolée n'a pas de « plus ancienne », et sans rechaînage `orders_total` resterait faux pour toutes les journées suivantes. Pas de modale de confirmation — geste idempotent, aucun argent déplacé, même parti pris que `Challenges\Show::resyncOrders()`.
