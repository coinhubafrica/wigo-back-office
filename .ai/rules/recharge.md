---
paths:
  - 'app/Notifications/**,app/Services/Shop/ShopOrderService.php,app/Services/Challenges/DrawService.php,app/Services/Recharge/RechargeService.php'
---

# Recharge

## Tout état que le conducteur doit apprendre passe par une Notification
L'écran « Notifications » du mobile lit la table `notifications` : un changement d'état sans classe Notification n'est pas seulement « non poussé », il est **absent de l'historique** du conducteur. Le seul recours est qu'il rouvre le bon écran.

Une notification poussée = `extends Notification implements ShouldQueue`, `use BuildsFcmMessage, Queueable`, et `via()` qui rend `$this->pushedChannels()`. Le push suit tout seul, rien à câbler.

Points de passage uniques, à ne pas contourner :
- Boutique : `ShopOrderService::transition()` — une seule notification (`ShopOrderStatusChanged`) pour tout le cycle. `ShopOrderStatusChanged::notifies()` décide quels états réveillent : ni `Ordered` ni `Collected` (le conducteur vient de commander, ou il est au comptoir). Le code de retrait ne voyage que sur `Ready` en mode `Pickup` — `collect()` refuse un code faux, et un trajet perdu coûte cher.
- Challenges : `DrawService::draw()`, pas les deux fabriques de gagnants — c'est là que tombola et surprise se rejoignent. Un challenge tiré sort de `GET /challenges` (qui ne liste que les `active`), donc sans notification le gagnant ne peut rien constater.
- Recharges : `to_review` (`RechargeNeedsReview`) est le cas qui coûte de l'argent — Wave a encaissé, Yango a refusé. `Log::error` et `AuditLog` ne parlent qu'aux agents. Ne jamais nommer Wave ou Yango dans le message : `wireStatus()` collapse `failed`/`to_review` pour le mobile, la nuance est interne.

Décisions inverses, à ne pas « corriger » : modération photo (supprimée), validation CNPS (n'a jamais existé), affectation véhicule (Yango, lecture seule), suspension back-office (supprimée).
