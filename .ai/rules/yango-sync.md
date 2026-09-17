---
paths:
  - 'app/Livewire/YangoSync/**'
---

# Yango Sync

## Rattrapage Yango : une journée par job, borné à 31, non journalisé
L'écran `bo.yango-sync` (groupe « Parc », droit `yango.resync-period`) remet en file les courses journée par journée, comme `yango:sync-orders --from/--to`, et recompte les cumuls sans rien redemander à Yango. Il vit là et **pas** dans « Paramètres » : `module.settings` n'est tenu que par l'administrateur, or le geste est celui de l'exploitation.

À ne pas défaire :
- **`MAX_DAYS = 31`.** Chaque journée est une boucle de curseur ; une année d'un clic se ferait refuser en 429 bien avant la fin. Au-delà, c'est la commande console.
- **Pas de garde anti-reclic dans le composant** : `SyncYangoOrdersJob` et `RebuildDailyActivityJob` sont `ShouldBeUnique` par journée, un second clic ne double rien. Le verrou vit dans le job.
- **Non journalisé**, comme `Challenges\Show::resyncOrders()` : rejeu de données Yango, aucun argent déplacé, rien d'irréversible. Un test négatif dans `AuditTrailTest` le verrouille.
- `Carbon::createFromFormat()` **lève** sur une chaîne illisible (elle ne rend pas `false`) : `days()` tourne à chaque rendu, y compris pendant que l'agent tape sa date, d'où le `catch (InvalidFormatException)`.

Le tableau d'historique est ce qui rend l'écran utile : sans lui l'agent relance à l'aveugle et recommence sans savoir si cela a servi. Il lit des cumuls (`yango_daily_stats`, `driver_daily_activities`, `yango_sync_runs`) et **jamais `yango_orders`** — c'est ce parcours de 2,3 M de lignes qui le faisait dépasser le délai.
