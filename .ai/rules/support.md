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

## Diffusions : audience figée, envoi rejouable, compte affiché avant l'envoi
Les destinataires sont **matérialisés** (`broadcast_recipients`, une ligne par conducteur, par lots de 500) et non recalculés à la lecture. Sans cela l'audience changerait sous les pieds du destinataire au gré de son statut, et le taux d'ouverture n'aurait pas de dénominateur.

Rejouable de bout en bout : l'unicité `(broadcast_id, driver_id)` absorbe une reprise, et **seules les lignes réellement insérées sont notifiées** — reprendre un envoi à moitié fait ne prévient donc personne deux fois. Cinq mille conducteurs notifiés en double, c'est un incident ; un test couvre ce cas.

Le nombre de destinataires affiché avant l'envoi sort du **même** `BroadcastAudienceResolver` que la matérialisation : un agent ne doit pas voir un nombre puis en toucher un autre. Attention au piège corrigé une fois déjà — la confirmation d'un brouillon déjà enregistré doit compter sur *sa* propre audience (`confirmingCount`), jamais sur l'état courant du composeur, qui vaut « tous » après un rechargement de page.

Une diffusion ne se répond pas : l'API mobile n'expose que la liste et le marquage en lu. Le bouton « Répondre » de l'application ouvre le fil du support, et `support_requests.opened_from_broadcast_id` garde le lien.

## Envois groupés : « campaign », pas « broadcast »
Un envoi groupé dépose **le même message dans la conversation de chaque conducteur visé** — un message système, que le conducteur lit là où il lit déjà le support et auquel il peut répondre sur place. Sa réponse repart en tri comme n'importe quel sujet nouveau.

Le nom compte : `Campaign` et non `Broadcast`. Le mot « broadcast » est déjà pris par Laravel (`ShouldBroadcast`, `Broadcast::channel()`, Reverb), et ce dépôt fait les deux. Deux sens pour un même terme est un piège — **ne pas renommer en sens inverse**, et se méfier d'un rechercher-remplacer global : il casse `broadcastOn()`, `ShouldBroadcast`, `/broadcasting/auth` et `config('broadcasting.default')`.

**Pas de table de destinataires.** Les messages déposés font foi : ils disent qui a reçu, et leur `read_at` dit qui a lu. `Campaign::readRate()` compte dessus plutôt que sur un compteur — un `read_at` ne dérive pas, et il atteste d'un fil réellement ouvert, pas d'une notification balayée.

Rejouable : un conducteur qui a déjà reçu l'envoi est ignoré, donc reprendre un envoi à moitié fait ne dépose ni ne notifie deux fois. Écriture par lots de 500.

Aucun endpoint mobile : la composition est réservée au back-office, et la réception passe par le fil de conversation, la table `notifications` et le push FCM — les mêmes chemins que n'importe quel message.
