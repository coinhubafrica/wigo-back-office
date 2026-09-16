---
paths:
  - 'app/Livewire/YangoSync/**'
---

# Yango Sync

## Rattrapage Yango : une journée par job, borné à 31, non journalisé
L'écran `bo.yango-sync` (groupe « Parc », droit `yango.resync-period`) remet en file les courses et le grand livre journée par journée, comme `yango:sync-orders --from/--to`. Il vit là et **pas** dans « Paramètres » : `module.settings` n'est tenu que par l'administrateur, or le geste est celui de l'exploitation.

À ne pas défaire :
- **`MAX_DAYS = 31`.** Chaque journée est une boucle de curseur ; une année d'un clic se ferait refuser en 429 bien avant la fin. Au-delà, c'est la commande console.
- **Pas de garde anti-reclic dans le composant** : `SyncYangoOrdersJob` / `SyncYangoTransactionsJob` sont `ShouldBeUnique` par journée, un second clic ne double rien. Le verrou vit dans le job.
- **Non journalisé**, comme `Challenges\Show::resyncOrders()` : rejeu de données Yango, aucun argent déplacé, rien d'irréversible. Un test négatif dans `AuditTrailTest` le verrouille.
- `Carbon::createFromFormat()` **lève** sur une chaîne illisible (elle ne rend pas `false`) : `days()` tourne à chaque rendu, y compris pendant que l'agent tape sa date, d'où le `catch (InvalidFormatException)`.

Le tableau de couverture (deux requêtes groupées, jamais une par journée) est ce qui rend l'écran utile : sans lui l'agent relance à l'aveugle et recommence sans savoir si cela a servi.
