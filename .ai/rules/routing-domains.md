---
paths:
  - 'routes/web.php'
  - 'routes/api.php'
---

# Routage — deux domaines

## `wigo.ci` sert la vitrine ; `support.wigo.ci` sert tout le reste

Une seule application, deux façades. `routes/web.php` porte deux groupes `Route::domain(...)` et `routes/api.php` un troisième, tous alimentés par `config('wigo.domains.*')` (`WIGO_SITE_DOMAIN`, `WIGO_BACK_OFFICE_DOMAIN`).

**En ajoutant une route, choisir son groupe.** Une route publique destinée aux chauffeurs et aux visiteurs va dans le groupe vitrine ; tout le reste — back-office, API mobile, documentation, retours de paiement — dans le groupe back-office. Il n'y a plus de `routes/site.php` ni de `routes/docs.php` : les docs ont été remontées dans `web.php` et le `then:` de `bootstrap/app.php` a disparu avec elles.

## `Route::domain(null)` est une contrainte neutre — ne pas l'entourer d'un `if`

Vérifié dans le framework : `Route::getDomain()` teste `isset($this->action['domain'])`, faux pour `null` ; l'expression d'hôte compilée reste nulle et `HostValidator::matches()` renvoie `true` sans condition. `RouteGroup::merge()` teste `isset()` aussi, donc un domaine nul n'écrase pas celui d'un groupe parent.

C'est ce qui fait tenir le développement local : les deux variables sont absentes, les contraintes tombent, et `/` sert la vitrine à côté de `/login` sur `localhost:8000`. `tests/Feature/Site/DomainRoutingTest.php` épingle ce comportement — s'il casse à une montée de version, c'est tout le développement local qui casse.

## `Route::redirect()` enregistre TOUS les verbes et peut écraser un `GET` déjà déclaré

Piège rencontré à l'implémentation. `RouteCollection` indexe par méthode : une route `ANY` déclarée après un `GET` de même URI remplace son entrée. La redirection de l'hôte nu du back-office (`bo.home`, `/` → `/login`) écrasait donc le `/` de la vitrine en local, **quel que soit l'ordre de déclaration**.

D'où sa condition dans `web.php` : elle n'existe que si le domaine back-office est configuré. L'ordre des groupes ne protège de rien ici — la condition, si.

## Ce qui reste volontairement sans contrainte d'hôte

- **`/up`** (déclaré par `withRouting(health:)`) : la plateforme le sonde parfois par nom interne ou par IP. Une contrainte d'hôte ferait échouer le contrôle de santé.
- **Routes internes des paquets** : Livewire, Sanctum, `storage/*`, `broadcasting/auth`. Déclarées hors de nos groupes, et protégées par authentification et non par hôte.

`tests/Feature/Site/DomainRoutingTest.php` liste ces exemptions et échoue sur toute route applicative qui sortirait des groupes.

## `config()` et jamais `env()` dans un fichier de routes

`config:cache` neutralise `env()` hors des fichiers de configuration. La lecture d'environnement reste confinée à `config/wigo.php`, où `?: null` ramène une variable présente mais vide (`''`) à `null` — `Route::domain('')` compilerait sinon une contrainte ne correspondant à rien.

## Le cache de routes fige les domaines

`route:cache` sérialise les routes déjà résolues, domaine compris : la mise en cache fonctionne, c'est vérifié. Mais changer `WIGO_SITE_DOMAIN` sans relancer `route:cache` laisse l'ancien domaine en place, et `route:list` rapportera lui aussi la valeur périmée puisqu'il lit le cache. **Le déploiement doit poser les variables avant `php artisan optimize`.**

## Webhook Wave : l'URL vit chez Wave, pas dans le code

`api/webhooks/wave/{account}` suit le domaine du back-office. Son URL de rappel est enregistrée **dans le tableau de bord Wave, par compte** (`shop` et `topup`). Elle doit viser `support.wigo.ci` avant toute bascule de domaine : un rappel qui répond 404 est tenu pour échoué par Wave, et les paiements cessent d'être confirmés **sans aucune erreur visible dans l'application**.

Même vigilance pour `payment/success|failed` : `SaloonWaveClient` fige ces URL par `route()` à la création de la session Checkout, donc une session ouverte avant une bascule retombera sur un 404 (impact cosmétique, le webhook créditant de son côté).

## `SESSION_DOMAIN` reste `null`

Réflexe fréquent en introduisant un sous-domaine, et à ne pas suivre ici. Posé à `.wigo.ci`, le cookie de session du back-office serait envoyé à la vitrine — un jeton d'authentification exposé sur une page publique et anonyme. Host-only est le bon défaut : les deux façades n'ont aucune raison de partager une session.
