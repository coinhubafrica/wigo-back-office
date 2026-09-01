# Temps réel — fil de support

> Contrat REST complet : `/docs/api`. Cette page ne couvre que le websocket.

Contrat de diffusion pour l'application mobile. Ce document décrit ce qui part
sur le websocket, à quel canal s'abonner, et comment s'y authentifier. Le
contrat REST (envoi/lecture des messages, pièces jointes) reste dans
`openapi.json` — ceci ne documente que ce qui n'y figure pas : les évènements
poussés en direct.

## Principe

Une trame websocket est **un signal, jamais une source de vérité**. Elle
annonce qu'quelque chose a changé ; l'application recharge l'état exact
(fil, compteur) depuis l'API REST plutôt que de construire l'écran à partir de
la charge utile. Deux raisons :

- La charge utile est volontairement pauvre (un aperçu tronqué, pas le corps
  complet) — voir plus bas pourquoi.
- Une trame ne peut jamais faire fuiter un contenu que le client n'a pas le
  droit de lire : l'autorisation reste entièrement du côté du canal
  (souscription) et de l'API (lecture), jamais dans le contenu de l'évènement.

Concrètement, à la réception de `message.sent` sur le fil ouvert, l'action
attendue est : rappeler `GET /api/v1/support/conversation/messages`, pas
afficher directement `event.preview`.

## Serveur

Auto-hébergé : [Laravel Reverb](https://reverb.laravel.com/), protocole
compatible Pusher. N'importe quel client Pusher standard (`pusher-js`,
`PusherSwift`, `pusher_channels_flutter`…) fonctionne sans adaptation.

| Paramètre client | Source |
|---|---|
| `key` | `VITE_REVERB_APP_KEY` (staging : demander la valeur, jamais commitée) |
| `host` / `wsHost` | `VITE_REVERB_HOST` |
| `port` / `wsPort` | `VITE_REVERB_PORT` |
| `scheme` / `forceTLS` | `VITE_REVERB_SCHEME` (`https` en staging/prod) |
| `authEndpoint` | `https://<host-api>/api/v1/broadcasting/auth` |

Ne pas utiliser `/broadcasting/auth` (sans le préfixe `api/v1`) : cette route
existe aussi, mais elle attend une session de navigateur (back-office) et
répondra 401 à un jeton Sanctum.

## Authentification du canal

Chaque canal utilisé ici est **privé** (`private-` côté protocole). Avant de
souscrire, le client Pusher poste automatiquement au `authEndpoint` ; il suffit
de lui fournir le jeton Sanctum du conducteur en `Authorization: Bearer
<token>` sur cette requête (le comportement par défaut de la plupart des SDK
Pusher une fois l'en-tête configuré globalement sur le client HTTP sous-jacent).

- `POST /api/v1/broadcasting/auth`
- Même garde que le reste du contrat mobile : `auth:sanctum`,
  `ability:mobile:*`, `throttle:mobile`. Un jeton dont l'habilitation n'est pas
  `mobile:*` ne peut s'abonner à rien.
- Un canal inconnu, ou qui n'appartient pas au conducteur authentifié,
  répond **403** — jamais 404, pour ne rien révéler sur l'existence d'une
  ressource à qui n'y a pas droit.

Aucun canal de présence n'est utilisé : un conducteur ne doit pas savoir quels
agents du support sont connectés.

## Canal à écouter

```
conversation.{conversationId}
```

`{conversationId}` est l'identifiant ULID renvoyé par
`GET /api/v1/support/conversation` (champ `id`). Un conducteur n'a qu'une
conversation, permanente ; l'application s'abonne à ce canal dès l'ouverture
de session (ou dès l'ouverture de l'écran support) et s'y désabonne à la
fermeture.

Le nom de canal complet côté protocole Pusher est `private-conversation.<id>`
— le préfixe `private-` est ajouté automatiquement par le SDK client à partir
du nom `conversation.<id>`, ne pas le préfixer soi-même.

## Évènements

Les deux évènements ci-dessous sont les seuls actuellement poussés vers un
conducteur. Les noms sont stables et volontairement courts — l'application ne
se lie pas à un nom de classe PHP.

### `message.sent`

Un message vient d'être déposé dans le fil : réponse d'un agent, message
système, ou message groupé (campagne). **Ne couvre pas** les messages que le
conducteur envoie lui-même — inutile de se notifier son propre envoi.

```json
{
  "id": "01m1eaasr4h80hs6yrsz89sg52",
  "conversation_id": "01m1eaajq3wkgtsa692vbb5sf5",
  "sender_type": "user",
  "sender_name": "Mariam KONÉ",
  "type": "text",
  "preview": "Nous avons bien reçu votre demande…",
  "created_at": "2026-09-01T11:05:00+00:00"
}
```

| Champ | Type | Notes |
|---|---|---|
| `id` | string (ULID) | Identifiant du message. |
| `conversation_id` | string (ULID) | Toujours celle du canal souscrit. |
| `sender_type` | `"user"` \| `"driver"` \| `null` | `"user"` = un agent du support. `null` = message système (campagne, évènement automatique). Jamais le conducteur lui-même. |
| `sender_name` | string \| `null` | Nom de l'agent, figé au moment de l'envoi. `null` pour un message système. |
| `type` | `"text"` \| `"attachment"` \| `"system"` | Décrit le message, pas l'émetteur. |
| `preview` | string | **Tronqué à 160 caractères. N'est pas le corps complet et ne doit pas être affiché comme tel** — la trame traverse aussi un canal partagé côté back-office. Utile pour une notification locale ou un indicateur « nouveau message », rien de plus. |
| `created_at` | string (ISO 8601) | |

**Action attendue côté client** : rappeler
`GET /api/v1/support/conversation/messages` (curseur en tête, donc le message
le plus récent) pour obtenir le message complet, avec pièces jointes le cas
échéant, puis mettre à jour le fil affiché.

### `message.read`

Un côté du fil vient de tout lire — l'agent a ouvert le ticket, ou le
conducteur a ouvert son fil depuis un autre appareil.

```json
{
  "conversation_id": "01m1eaajq3wkgtsa692vbb5sf5",
  "reader_type": "user",
  "read_at": "2026-09-01T11:06:12+00:00"
}
```

| Champ | Type | Notes |
|---|---|---|
| `conversation_id` | string (ULID) | |
| `reader_type` | `"user"` \| `"driver"` | Qui vient de lire. |
| `read_at` | string (ISO 8601) | Horodatage de lecture, commun à tous les messages couverts par cette lecture. |

La charge utile ne grossit pas avec le nombre de messages lus : elle porte un
horodatage, pas une liste d'identifiants. Le client déduit quelles bulles
marquer comme lues en comparant `read_at` à l'horodatage de chaque message déjà
affiché (tout message de l'autre partie antérieur ou égal à `read_at` est lu).
Concerne uniquement les bulles envoyées par l'autre partie ; l'application ne
doit pas s'auto-marquer lue à la réception de cet évènement pour ses propres
messages.

## Canaux réservés, non utilisés pour l'instant

`routes/channels.php` déclare aussi `driver.{driverId}` — canal personnel du
conducteur, prévu pour des notifications hors fil ouvert. **Aucun évènement
n'y est actuellement diffusé** ; la règle d'autorisation existe mais rien ne
la déclenche encore. Ne pas s'y abonner tant que ce document n'est pas mis à
jour pour annoncer un évènement dessus — l'autorisation ne sera pas retirée
sans préavis, mais aucun contrat de charge utile n'existe encore.

## Repli sans websocket

Le canal `conversation.{id}` peut être temporairement indisponible (Reverb en
maintenance, coupure réseau). Le back-office garde dans ce cas un
rafraîchissement périodique en secours ; l'application mobile devrait faire de
même — un appel périodique à `GET /api/v1/support/unread` (léger, pas de
pagination) suffit à détecter un nouveau message sans écouter le socket.

## Client Flutter

Intégration détaillée (paquet, authentification, abonnement, cycle de vie) :
[`/docs/api/realtime/flutter`](/docs/api/realtime/flutter).

## Fichiers de référence

| Sujet | Fichier |
|---|---|
| Définition des évènements | `app/Events/Support/MessageSent.php`, `app/Events/Support/MessageRead.php` |
| Règles d'autorisation des canaux | `routes/channels.php` |
| Route d'authentification mobile | `routes/api.php` (recherche `Broadcast::routes`) |
| Configuration Reverb | `config/broadcasting.php`, `.env.example` |
