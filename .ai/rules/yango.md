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

`HttpFleetClient` a été supprimé : il lisait `config('services.fleet.*')`, soit une seconde source d'identifiants pour le même parc — jamais renseignée en pratique, si bien que les crédits partaient avec un jeton vide et échouaient en silence (`isConfigured()` ne regardait que l'URL). Les variables `FLEET_BASE_URL`/`FLEET_API_KEY`/`FLEET_PARK_ID` sont retirées, et `YANGO_DRIVER` avec elles (cf. « Pas de doublure Yango » plus bas) : il ne reste aucune variable d'environnement Yango.

Contrat d'erreur inchangé et volontairement inverse : `YangoDirectory` (`SaloonYangoDirectory`) lève, `YangoClient` (`SaloonYangoClient`) rend `false`/`null`.

## L'espacement vit dans la pagination, et la passe ne se découpe jamais
Yango répond 429 quand une passe enchaîne ses pages sans pause. L'espacement (`YangoSettings::$page_delay_ms`, 250 ms par défaut, 0 pour désactiver) et le rejeu sur 429 vivent dans `SaloonYangoDirectory`, **pas** dans le connecteur : le 429 naît de la rafale de la boucle, pas d'un appel isolé, et le connecteur est partagé avec `YangoConnectionTester` (un appel, derrière un bouton de l'écran Paramètres) et `SaloonYangoClient` (chemin d'argent). La pause précède l'envoi plutôt que de suivre le `yield from`, si bien qu'un consommateur qui abandonne le générateur — le testeur de connexion, qui sort après une ligne — ne la paie jamais. Un test le verrouille.

Piège découvert en chemin, puis supprimé : **`$tries` sur un connecteur ne rejoue rien ici.** `SendsRequests::send()` ne rattrape que `FatalRequestException|RequestException` ; `YangoFleetException` et `WaveException` descendent de `Exception` et lui échappent. Les trois propriétés (`$tries`, `$retryInterval`, `$useExponentialBackoff`) ont donc été retirées des deux connecteurs — vérifié à l'expérience : un 500 partait une fois, pas trois. **Ne pas les réintroduire** en croyant rétablir un rejeu.

On ne change pas non plus la hiérarchie d'exceptions pour les rendre vivantes : `RequestException::getResponse()` n'est pas nullable, alors que nos exceptions se construisent aussi sans réponse (identifiants absents). Le rejeu est écrit dans `SaloonYangoDirectory::fetchPage()`, où notre type est attrapable, et seulement pour le 429.

`Retry-After` est honoré quand Yango le donne, sinon 30 s, plafonné à 120 s : un en-tête aberrant ne doit pas immobiliser un worker. L'attente passe par la façade `Sleep` et non par `usleep` — c'est la seule forme qu'un test peut feindre.

**La passe parc ne se découpe jamais en plusieurs jobs.** `reportStale()` compare `last_sync_at` à un repère posé avant la première écriture ; un second job compterait comme « non remontées » toutes les lignes que le premier n'a pas encore atteintes. Un job = une passe = un repère. L'espacement ralentit la passe, il ne la divise pas.

Cette règle vaut pour le parc, **pas** pour les courses et les transactions : celles-ci sont bornées par une date et ne tiennent aucun repère. Une journée par job y est donc légitime, et souhaitable — une période d'un mois qui échoue au vingtième jour ne refait pas les dix-neuf précédents.

## Deux paginations, et rien pour les unifier
Yango en expose deux, et elles ne se ramènent pas l'une à l'autre.

- **Parc** (`/v1/parks/driver-profiles/list`, `/v1/parks/cars/list`) : décalage, `limit` **1000 au plus**, et la réponse porte un **`total`**. C'est lui qui dit où s'arrêter — on ne devine plus la fin à une page incomplète. Une réponse muette sur `total` retombe sur l'ancien critère (page pleine), et une page vide arrête la boucle en toutes circonstances, faute de quoi un `total` trop grand la ferait tourner sans fin. Le décalage avance de `count($page)` et non de `$pageSize` : une page courte au milieu sauterait sinon des lignes.
- **Journaux datés** (`/v1/parks/orders/list`, `/v2/parks/transactions/list`) : **curseur**, aucun `total`, fenêtre de dates obligatoire pour les courses (`booked_at` ou `ended_at` — on filtre sur `ended_at`, c'est la fin de course qui décide du jour d'activité). On redemande tant qu'un curseur revient. Le premier appel part **sans** la clé `cursor` : Yango lui impose une longueur minimale de 1, une chaîne vide serait refusée.

Plafonds à ne pas confondre : 1000 pour le parc et les transactions, **500 pour les courses**. Et le piège des transactions : `limit` y vaut **40 par défaut** côté Yango — le laisser implicite fait vingt-cinq fois trop d'appels.

**Le plafond n'est pas le régime de croisière.** Chaque requête porte donc deux constantes : `MAX_LIMIT`, ce que Yango accepte, et `DEFAULT_LIMIT`, ce qu'on lui demande vraiment — la moitié. Réclamer le maximum à chaque page faisait refuser la passe en 429 avant qu'elle ait fini le parc, contre l'API vivante. `MAX_LIMIT` ne sert plus qu'à borner ce qu'un `--limit` peut réclamer ; les valeurs par défaut des jobs, services et commandes partent de `DEFAULT_LIMIT`.

L'espacement se règle en base (`YangoSettings::$page_delay_ms`) et non dans le code, précisément parce que le bon palier s'observe : 250 ms ne suffisait pas sur un parc de dix mille conducteurs, 2000 ms tient. Un 429 qui persiste se corrige d'abord là, avant de toucher au code.

## Les montants Yango sont des chaînes décimales
`amount`, `price`, `balance`, `mileage` arrivent en chaîne à quatre décimales (« 12345.1434 »), jamais en nombre. `yango_transactions.amount` est donc un `decimal(20,4)` et la valeur ne passe jamais par un `float` — ce serait perdre des centimes sur les gros montants.

À distinguer de `transactions.amount`, entier de FCFA : c'est l'argent **local** (Wave encaisse), pas le grand livre du parc. Les deux tables se rapprochent, elles ne fusionnent pas.

## Une course exige un conducteur, une transaction non
`yango_orders.driver_id` est requis : une course dont le conducteur n'a pas de ligne locale — le plus souvent un profil écarté faute de téléphone exploitable — est comptée, journalisée, **jamais écrite**. Inventer un conducteur ferait pire que le trou qu'on comble.

`yango_transactions.driver_id` est au contraire **nullable** : toutes les écritures du parc ne visent pas quelqu'un, et le grand livre doit rester complet là même où le rapprochement échoue. Une ligne sans conducteur est écrite et comptée à part.

## Le solde du parc arrive gratuitement avec les conducteurs
`GetAllDriversRequest` demande déjà `fields.account`, et la passe le jetait. `YangoSyncService` le lit désormais par `YangoAccountBalance::read()` — la même lecture que `SaloonYangoClient::balanceFor()`, extraite pour que les deux chemins ne puissent pas diverger et afficher deux soldes pour le même conducteur.

Un solde absent n'écrase rien : `null` n'est pas zéro.

## `yango:sync` met en file, `--now` exécute sur place
La commande dispatche `SyncYangoJob` par défaut ; `--now` lance la passe en ligne avec les compteurs à l'écran. Le planificateur appelle la commande sans `--now` : une passe espacée ne doit pas bloquer le processus du planificateur.

Le chemin planifié perd donc sa sortie console et son code d'échec — assumé, le planificateur la jetait déjà. `SyncYangoJob` journalise les compteurs en `Log::info`, et une clé refusée atterrit dans `failed_jobs`.

`$timeout = 1800` sur le job **oblige** `retry_after` (défaut porté à 1860 dans `config/queue.php`) à rester au-dessus : en dessous, le worker reprendrait le job en cours de passe et deux passes tourneraient de front sur les mêmes lignes. Le verrou `ShouldBeUnique` passe par le magasin de **cache** — un environnement en `array` ou `file` rendrait l'unicité illusoire.

## Pas de doublure Yango : `MockClient` est la seule simulation
`FakeYangoClient` et `FakeYangoDirectory` ont été supprimés. L'API Yango ne se simule que par le `MockClient` de Saloon, qui exerce le connecteur, les requêtes et le décodage au lieu de les court-circuiter. Deux mécaniques pour feindre la même API, c'en était une de trop.

Conséquence assumée : **`YANGO_DRIVER` n'existe plus**, et `config/services.php` n'a plus de bloc `yango`. Le développement local parle à la vraie API avec les identifiants saisis dans « Paramètres », ou échoue comme en production. Ce n'est pas une régression, c'est le prix payé — ne pas réintroduire de pilote `fake`. (Wave garde le sien : `FakeWaveClient` n'est pas concerné.)

Écrire un test qui touche Yango :

- `yangoConfigure()` d'abord, sinon `isConfigured()` refuse de sortir et aucune requête n'atteint le mock.
- Les fabriques de charge utile vivent dans `tests/Pest.php` : `yangoProfile()`, `yangoCar()`, `yangoDriversResponse()`, `yangoVehiclesResponse()`, `yangoBalanceResponse()`, `yangoRefusal()`, `yangoOrderRow()`, `yangoOrdersResponse()`, `yangoTransactionRow()`, `yangoTransactionsResponse()`. Les deux premières listes portent un `total` (nul pour exercer le repli), les deux dernières un `cursor` (vide = dernière page).
- **Indexer par classe de requête** (`GetAllDriversRequest::class => ...`) plutôt qu'en séquence : l'ordre des appels devient sans importance, et un rejeu (429, quatre tentatives) ne vide pas la file de réponses.
- `MockClient::destroyGlobal()` en `afterEach`, sans exception : un mock global qui fuit contamine les fichiers suivants.

Deux pièges vérifiés à l'expérience :

1. **`MockClient::global()` utilise `??=`** : appelé alors qu'un global existe déjà, il rend l'ancien et **ignore silencieusement** les nouvelles réponses. Pour remplacer le mock d'un `beforeEach` dans un test précis, il faut `MockClient::destroyGlobal()` juste avant.
2. **Une réponse indexée par classe est resservie à chaque appel de cette classe.** `SaloonYangoDirectory::paginate()` redemande tant qu'une page est pleine : une page simulée qui fait exactement `pageSize` boucle à l'infini. Garder les pages simulées plus courtes, ou passer une closure pour rendre des pages successives.
