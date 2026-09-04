---
paths:
  - 'app/Services/Support/**'
  - app/Services/Support/BroadcastDispatcher.php
  - app/Services/Support/CampaignDispatcher.php
---

# Support

## Un fil permanent côté conducteur, des tickets côté back-office
Deux lectures d'un même stock de messages. Le conducteur voit **une** conversation, permanente, une par conducteur (`conversations.driver_id` est unique) : il ignore l'existence des tickets. Le back-office voit des `support_requests` découpés dedans, assignables et chronométrés. Résoudre un ticket ne ferme donc rien pour le conducteur ; son message suivant repart en tri.

`messages.conversation_id` est obligatoire, `support_request_id` facultatif. Trois états de tri se déduisent sans énumération : non trié (les deux nuls), écarté (`triaged_at` seul), rattaché (`support_request_id`).

L'émetteur tient dans la seule relation polymorphe `sender` ; son absence signifie « message système ». **Ne pas réintroduire de colonne discriminante** : elle pourrait diverger de `sender_type`.

Deux invariants portés par `MessageService`, et nulle part ailleurs :
- envoyer vaut lecture (l'expéditeur ne peut pas accumuler du non-lu sur son propre message) ;
- un message entrant se rattache au ticket vivant s'il y en a un, sinon il reste à trier — le tri ne se déclenche que sur un sujet nouveau.

La priorité et les deux échéances SLA sont **dérivées** de la catégorie par `SlaCalculator`, jamais saisies, et stockées : retoucher le barème ne doit pas rejouer les tickets passés.

## Le compte affiché avant l'envoi vient du même résolveur que l'envoi
Le nombre de destinataires montré avant de diffuser sort du **même** `CampaignAudienceResolver` que la matérialisation : un agent ne doit pas voir un nombre puis en toucher un autre. Piège déjà corrigé une fois — la confirmation d'un brouillon déjà enregistré doit compter sur *sa* propre audience (`confirmingCount`), jamais sur l'état courant du composeur, qui vaut « tous » après un rechargement de page.

## Envois groupés : « campaign », pas « broadcast »
Un envoi groupé dépose **le même message dans la conversation de chaque conducteur visé** — un message système, que le conducteur lit là où il lit déjà le support et auquel il peut répondre sur place. Sa réponse repart en tri comme n'importe quel sujet nouveau.

Le nom compte : `Campaign` et non `Broadcast`. Le mot « broadcast » est déjà pris par Laravel (`ShouldBroadcast`, `Broadcast::channel()`, Reverb), et ce dépôt fait les deux. Deux sens pour un même terme est un piège — **ne pas renommer en sens inverse**, et se méfier d'un rechercher-remplacer global : il casse `broadcastOn()`, `ShouldBroadcast`, `/broadcasting/auth` et `config('broadcasting.default')`.

Les destinataires sont **matérialisés** dans `campaign_recipients` — voir la section « Les destinataires d'une campagne sont matérialisés » plus bas, qui remplace l'ancienne règle « pas de table de destinataires ». `Campaign::readRate()` continue de compter sur les messages déposés plutôt que sur un compteur : un `read_at` ne dérive pas, et il atteste d'un fil réellement ouvert, pas d'une notification balayée.

Aucun endpoint mobile : la composition est réservée au back-office, et la réception passe par le fil de conversation, la table `notifications` et le push FCM — les mêmes chemins que n'importe quel message.

## L'image d'une campagne est stockée une fois, mais portée par une ligne de pièce jointe par destinataire
La campagne porte le fichier (`campaigns.image_disk` / `image_path` / `image_name` / `image_mime` / `image_size_bytes`), téléversé **une seule fois** à la composition. L'envoi crée ensuite une ligne `message_attachments` par conducteur, toutes sur le **même** `path` : ce sont des métadonnées, pas des copies. Cinq mille conducteurs ne font donc pas cinq mille copies du même JPEG.

Ce découpage est ce qui permet de ne rien changer au contrat de l'API : `MessageResource.attachments` et le scope par conversation de `SupportController::downloadAttachment` fonctionnent tels quels, chaque conducteur ne pouvant tirer que la ligne accrochée à *son* message.

Corollaire à ne pas perdre de vue : **supprimer le fichier casse tous les messages de l'envoi**, jamais un seul. Ne pas « nettoyer » un `path` parce qu'une ligne disparaît.

Le message reste `MessageType::System` avec sa pièce jointe — l'application branche sur `system_event`, le basculer en `Attachment` lui ferait perdre l'évènement. `writeSystemMessage()` accepte donc un `$attachments`.

Disque : `local`, qui est le disque **privé** (racine `storage/app/private`), comme les pièces jointes du support — pas `public`, et pas `FILESYSTEM_DISK` (qui vaut `public` en local). Il n'existe aucun disque nommé `private` dans `config/filesystems.php`, malgré la racine : `Storage::fake('private')` en fabriquerait un et masquerait l'erreur en test.

Images seulement (`jpg,jpeg,png,webp`, 5 Mo) : aucun antivirus dans la chaîne, même borne que le support.

La charge utile de `CampaignPublished` porte `has_image` (booléen) et **pas** d'URL : elle est écrite en base et relue longtemps après, alors qu'une URL signée expire en une heure.

## Les destinataires d'une campagne sont matérialisés ; la réservation, pas la lecture, empêche le double envoi
**Cette règle renverse « Pas de table de destinataires ».** Ce choix tenait tant qu'un envoi ne pouvait qu'aboutir : un conducteur dont le message n'a jamais été écrit n'avait alors aucune ligne, donc n'apparaissait nulle part, et l'échec était invisible autant qu'irrattrapable. `campaign_recipients` est cet endroit manquant.

Partage des rôles, à ne pas brouiller :
- `campaign_recipients` porte l'état de la **remise** (`pending` / `sent` / `failed`, plus `error` et `attempts`) ;
- `messages.read_at` porte l'état de la **lecture**, et lui seul. Aucun drapeau de lecture n'est recopié sur la ligne destinataire — un `read_at` ne dérive pas, c'est ce qui en fait une preuve.

`readRate()` garde pour dénominateur les **messages déposés** : un conducteur qui n'a rien reçu ne peut pas lire. Le taux de remise (`deliveryRate()`) se compte sur les visés. Mélanger les deux rendrait les deux chiffres illisibles.

**Idempotence.** Le docblock de `DispatchCampaignJob` affirmait que la reprise était « absorbée par l'unicité `(campaign_id, driver_id)` en base » alors que cette contrainte n'existait pas : la garde réelle était un `whereIn` en PHP, par lot et hors transaction, que deux workers pouvaient franchir ensemble. Désormais :
1. `insertOrIgnore` sur `UNIQUE (campaign_id, driver_id)` — rematérialiser n'ajoute personne deux fois ;
2. **réserver avant d'écrire** : `UPDATE ... WHERE claimed_at IS NULL`, atomique sur MySQL comme SQLite, un seul worker en ressort avec une ligne modifiée.

L'ordre est la garantie : réserver **puis** écrire le message. Inverser, c'est retrouver la course. Le mode de défaillance devient la sous-livraison (ligne réservée non remise, rattrapable), jamais la sur-livraison — le bon compromis quand notifier deux fois est un incident.

**Le job de lot attrape par destinataire et ne lève jamais.** Ce n'est pas de la politesse : en file `sync` (donc en test), une exception qui sort d'un job de lot remonte jusqu'à l'appelant, `allowFailures()` ne l'avale pas et le rappel `finally` — qui sort la campagne de `Sending` — ne tourne pas. Vérifié à l'expérience.

Un job par **lot de 200**, pas par destinataire : la granularité d'échec vient de la ligne, qui survit à la purge de `failed_jobs` et se requête depuis l'écran. Le rejeu, lui, reste unitaire.

`Failed` ne se pose que si **rien** n'est parti. Une campagne remise à 4 999 sur 5 000 est envoyée ; ses échecs se lisent sur sa page.
