---
paths:
  - 'app/Services/Yango/**'
---

# Yango

## Synchronisation Yango Fleet : identifiant, adoption par téléphone, statut jamais réécrit
`YangoSyncService` (commande `yango:sync`, job `SyncYangoJob`, planifié à l'heure) rapproche le parc Yango via Saloon (`app/Http/Integrations/Yango/`).

Décisions à ne pas redéfaire :

- **Identifiant conducteur = `driver_profile.id`**, jamais `accounts.0.id`. Le projet d'origine (alal-pro) mélangeait les deux selon le chemin de code ; `driver_profile.id` est celui que l'API attend en `contractor_profile_id`.
- **Rapprochement en trois temps** : `yango_id`, sinon téléphone normalisé E.164 (*adoption* : on pose `yango_id` sur la ligne existante, créée à l'inscription mobile), sinon création. Un profil sans téléphone exploitable est ignoré et journalisé — `drivers.phone` est requis et unique.
- **Le `status` d'un conducteur existant n'est jamais réécrit.** Une suspension est une décision du back-office (`suspension_reason`) ; Yango n'a pas à la défaire. Un conducteur créé par la synchronisation naît `Dormant` (aucune CGU acceptée).
- **Les enregistrements que Yango ne remonte plus sont signalés, jamais modifiés** : compteurs dans le résumé de la commande + `Log::warning`. Pas de désactivation automatique — une absence peut venir d'une panne Yango.
- **`YangoDirectory` lève, `YangoClient` rend `null`.** Contrat d'erreur volontairement inverse : une passe interrompue au milieu ne doit pas écrire un parc tronqué. D'où deux contrats séparés.
- `SyncYangoJob` **échoue franchement sur 401/403** (clé refusée, inutile de réessayer), remet en file sinon — 429 compris.

Un véhicule tient sur une seule ligne : une réaffectation déplace `driver_id` (cf. `.ai/rules/models.md`). La passe « parc » ne touche pas `driver_id`, pour ne pas détacher ce que la passe « conducteurs » vient de rattacher.

## Yango : un seul chemin, Saloon + YangoSettings
Tout appel à l'API Yango passe par Saloon (`app/Http/Integrations/Yango/`) et lit ses identifiants dans `YangoSettings` (base, chiffrés), résolus à l'appel.

`HttpFleetClient` a été supprimé : il lisait `config('services.fleet.*')`, soit une seconde source d'identifiants pour le même parc — jamais renseignée en pratique, si bien que les crédits partaient avec un jeton vide et échouaient en silence (`isConfigured()` ne regardait que l'URL). Les variables `FLEET_BASE_URL`/`FLEET_API_KEY`/`FLEET_PARK_ID` sont retirées ; seul `YANGO_DRIVER` (`fake`|autre) reste.

Contrat d'erreur inchangé et volontairement inverse : `YangoDirectory` (`SaloonYangoDirectory`) lève, `YangoClient` (`SaloonYangoClient`) rend `false`/`null`.

## L'espacement vit dans la pagination, et la passe ne se découpe jamais
Yango répond 429 quand une passe enchaîne ses pages sans pause. L'espacement (`YangoSettings::$page_delay_ms`, 250 ms par défaut, 0 pour désactiver) et le rejeu sur 429 vivent dans `SaloonYangoDirectory`, **pas** dans le connecteur : le 429 naît de la rafale de la boucle, pas d'un appel isolé, et le connecteur est partagé avec `YangoConnectionTester` (un appel, derrière un bouton de l'écran Paramètres) et `SaloonYangoClient` (chemin d'argent). La pause précède l'envoi plutôt que de suivre le `yield from`, si bien qu'un consommateur qui abandonne le générateur — le testeur de connexion, qui sort après une ligne — ne la paie jamais. Un test le verrouille.

Piège découvert en chemin : **`$tries` sur `YangoFleetConnector` n'a jamais rejoué quoi que ce soit.** `YangoFleetException` ne descend pas de la `RequestException` de Saloon, que la boucle de rejeu interne est seule à attraper. On ne change pas cette hiérarchie — `RequestException::getResponse()` n'est pas nullable, alors que l'exception se construit aussi sans réponse (identifiants absents). Le rejeu est donc écrit dans `fetchPage()`, où notre type est attrapable.

`Retry-After` est honoré quand Yango le donne, sinon 30 s, plafonné à 120 s : un en-tête aberrant ne doit pas immobiliser un worker. L'attente passe par la façade `Sleep` et non par `usleep` — c'est la seule forme qu'un test peut feindre.

**La passe ne se découpe jamais en plusieurs jobs.** `reportStale()` compare `last_sync_at` à un repère posé avant la première écriture ; un second job compterait comme « non remontées » toutes les lignes que le premier n'a pas encore atteintes. Un job = une passe = un repère. L'espacement ralentit la passe, il ne la divise pas.

## `yango:sync` met en file, `--now` exécute sur place
La commande dispatche `SyncYangoJob` par défaut ; `--now` lance la passe en ligne avec les compteurs à l'écran. Le planificateur appelle la commande sans `--now` : une passe espacée ne doit pas bloquer le processus du planificateur.

Le chemin planifié perd donc sa sortie console et son code d'échec — assumé, le planificateur la jetait déjà. `SyncYangoJob` journalise les compteurs en `Log::info`, et une clé refusée atterrit dans `failed_jobs`.

`$timeout = 1800` sur le job **oblige** `retry_after` (défaut porté à 1860 dans `config/queue.php`) à rester au-dessus : en dessous, le worker reprendrait le job en cours de passe et deux passes tourneraient de front sur les mêmes lignes. Le verrou `ShouldBeUnique` passe par le magasin de **cache** — un environnement en `array` ou `file` rendrait l'unicité illusoire.

## Pas de doublure Yango : `MockClient` est la seule simulation
`FakeYangoClient` et `FakeYangoDirectory` ont été supprimés. L'API Yango ne se simule que par le `MockClient` de Saloon, qui exerce le connecteur, les requêtes et le décodage au lieu de les court-circuiter. Deux mécaniques pour feindre la même API, c'en était une de trop.

Conséquence assumée : **`YANGO_DRIVER` n'existe plus**, et `config/services.php` n'a plus de bloc `yango`. Le développement local parle à la vraie API avec les identifiants saisis dans « Paramètres », ou échoue comme en production. Ce n'est pas une régression, c'est le prix payé — ne pas réintroduire de pilote `fake`. (Wave garde le sien : `FakeWaveClient` n'est pas concerné.)

Écrire un test qui touche Yango :

- `yangoConfigure()` d'abord, sinon `isConfigured()` refuse de sortir et aucune requête n'atteint le mock.
- Les fabriques de charge utile vivent dans `tests/Pest.php` : `yangoProfile()`, `yangoCar()`, `yangoDriversResponse()`, `yangoVehiclesResponse()`, `yangoBalanceResponse()`, `yangoRefusal()`.
- **Indexer par classe de requête** (`GetAllDriversRequest::class => ...`) plutôt qu'en séquence : l'ordre des appels devient sans importance, et un rejeu (429, quatre tentatives) ne vide pas la file de réponses.
- `MockClient::destroyGlobal()` en `afterEach`, sans exception : un mock global qui fuit contamine les fichiers suivants.

Deux pièges vérifiés à l'expérience :

1. **`MockClient::global()` utilise `??=`** : appelé alors qu'un global existe déjà, il rend l'ancien et **ignore silencieusement** les nouvelles réponses. Pour remplacer le mock d'un `beforeEach` dans un test précis, il faut `MockClient::destroyGlobal()` juste avant.
2. **Une réponse indexée par classe est resservie à chaque appel de cette classe.** `SaloonYangoDirectory::paginate()` redemande tant qu'une page est pleine : une page simulée qui fait exactement `pageSize` boucle à l'infini. Garder les pages simulées plus courtes, ou passer une closure pour rendre des pages successives.
