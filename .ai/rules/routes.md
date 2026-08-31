---
paths:
  - routes/channels.php
---

# Routes

## Canaux privés : enregistrement au boot, deux gardes, deux pièges
`Broadcast::channel($nom, $callback)` déclare une **règle d'accès**. Ne pas confondre avec `Broadcast::private()`, qui diffuse un évènement anonyme et n'enregistre rien : l'abonnement serait alors refusé sans que rien ne le signale.

Deux pièges coûteux, tous deux silencieux parce qu'un canal inconnu se refuse de toute façon :

1. **`withRouting(channels: ...)` est à proscrire ici.** Il appelle `withBroadcasting()` *sans attributs* : `/broadcasting/auth` ne porte alors que `web`, sans authentification, et une requête anonyme atteint les règles d'accès avec un utilisateur nul. Et comme les deux passent par un `require`, déclarer les canaux une seconde fois serait un coup dans l'eau — PHP n'exécute le fichier qu'une fois. `bootstrap/app.php` utilise donc `->withBroadcasting(..., attributes: ['middleware' => ['web', 'auth', 'user.active']])`.

2. **`Broadcast::channel()` s'applique au pilote par défaut de l'instant** (passage par `__call` → `driver()`), et le fichier est chargé au démarrage. Avec `BROADCAST_CONNECTION=null` — le réglage de `phpunit.xml` — les règles atterrissent sur le pilote nul. Un test qui bascule la connexion ensuite doit recharger `routes/channels.php` (voir `tests/Feature/Support/ChannelAuthorizationTest.php`).

Deux routes d'autorisation, une par garde : session dans `bootstrap/app.php`, jeton Sanctum dans `routes/api.php` (`Broadcast::routes()` dans le groupe `ability:mobile:*`). Une seule route ne saurait pas servir les deux.

Canaux privés uniquement : un canal de présence publierait la liste de ses membres.
