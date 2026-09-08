---
paths:
  - 'app/Http/Controllers/Api/V1/ChallengeController.php,app/Http/Resources/DriverChallengePayload.php,app/Services/Challenges/**'
---

# Challenges

## Le règlement d'un challenge tient sur la ligne, servi par URL signée
Un challenge porte **un seul** règlement, en colonnes sur `challenges` (`rules_document_disk|path|name|mime|size|uploaded_at`) — pas de table dédiée : il n'y a rien à collectionner et un remplacement écrase le précédent (et supprime l'ancien fichier du disque). Ne pas introduire de `challenge_documents`.

Fichier sur le disque privé `local`. Deux portes, jamais le chemin de stockage : `api.v1.challenges.rules` (middleware `signed`, URL d'une heure posée par `DriverChallengePayload::rulesDocument()`) et `bo.challenges.rules-document` (session + permission du module). Les deux **résolvent le modèle à la main** et répondent 403 pour un challenge inconnu *comme* pour un challenge sans règlement — l'écart 403/404 dirait quels challenges existent. Le 404 ne subsiste qu'après autorisation, fichier absent du disque.

Piège : l'enveloppe d'erreur de `bootstrap/app.php` aplatit **tout** 404 sur `__('api.not_found')`. Un message passé en 3e argument d'`abort_unless` ne ressort jamais — ne pas ajouter de clé de langue pour ça (elle serait morte, comme `api.shop.document_missing`).

Route back-office déclarée **avant** `challenges/{challenge}`, sinon la route de détail l'absorbe.

`challenges.manage-rules` / gate `manageChallengeRules` : geste à part du module, car les conducteurs se fondent sur ce document pour savoir ce qui leur est promis. Joindre et retirer sont journalisés (`challenge.rules_attached` / `challenge.rules_removed`), le retrait **avant** le `delete()`.

`ticketing.tickets` liste les tickets du conducteur (`{id, date, range_number}`, du plus ancien au plus récent). `range_number` est `null` jusqu'au gel du vivier. La liste sert aussi de compteur : `tickets_held` vaut `count($tickets)` — l'ancien `ticketsHeld()` a été retiré, ne pas rétablir une 2e requête.

## Les tickets se comptent sur la période, et par différence
`ChallengeTicketMinter::mintFor()` émet `intdiv(courses terminées sur [period_start, period_end], trips_per_ticket) − tickets détenus`. Trois choses en découlent, chacune corrigeant un défaut vécu :

- **Période, pas carrière.** L'ancien calcul divisait `driver_daily_activities.orders_total`, un compteur à vie : un conducteur arrivant avec 149 courses gagnait un ticket à sa première course du challenge, et le mobile (`DriverProgressService::ticketing()`, période-scopé) le démentait. `orders_total` reste le cumul de carrière et ne sert plus à émettre.
- **Idempotence par compte, jamais par date.** La garde d'avant cherchait un ticket portant la journée traitée : la passe horaire rejouant le jour en cours, les courses de l'après-midi ne donnaient plus jamais de ticket. Ne pas rétablir un `exists()` sur `(challenge, driver, date)`.
- **Le rattrapage est possible** : rien n'est lié au jour synchronisé, donc un challenge démarré en cours de période se rattrape en une passe.

`DrawPending` **n'émet plus** (`Active` seulement) : le vivier y est gelé, numéroté et haché, et un ticket sans `range_number` ferait échouer `DrawService::drawRaffle()`.

`challenge_tickets.sequence` (rang du ticket chez son porteur) porte `unique (challenge_id, driver_id, sequence)`. C'est **la** garantie du compte : trois écrivains peuvent minter le même conducteur en même temps (passe horaire, tirage mobile, `challenges:advance`), et un `count` suivi d'un `insert` n'est atomique que par la base. Le `lockForUpdate` sur le conducteur ne fait que réduire le bruit ; le minter prend en plus un `sharedLock` sur le challenge, contre lequel `freezePool()` prend un verrou exclusif — soit le mint passe avant le gel, soit il lit `DrawPending` et s'arrête.

La `date` d'un ticket est le jour de la **course qui a franchi la tranche**, pas le jour du mint : le mobile la présente comme « le jour où le ticket a été gagné », et le gel ordonne par `(date, id)`.

## Tout conducteur participe à tout challenge
Il n'y a **pas d'inscription**, donc aucun filtre « participant » à écrire nulle part — ni pour émettre, ni pour rafraîchir, ni pour resynchroniser. `ChallengeRanking::participants()` filtre ce qu'il y a à *afficher* (qui a roulé, qui détient un ticket) ; ce n'est pas une règle d'éligibilité.

Conséquence sur le rattrapage : « resynchroniser les participants » se traduit par une passe **parc**, une par journée de la période (`Show::resyncOrders()`), et non par une passe par conducteur — cf. `.ai/rules/yango.md`.

## `challenges:advance` démarre et clôt, à la demi-heure
Rien n'ouvrait `Scheduled` : le formulaire et l'approbation le posent pour une tombola, et seul `Active` émet des tickets et remonte au mobile — une tombola n'a donc jamais démarré en production. `ChallengeLifecycleService::activateDue()` la démarre (jamais `PendingApproval` : une approbation est un geste humain), `closeDue()` la clôt.

Deux réglages à ne pas resserrer :

- **`hourlyAt(30)`** : `yango:sync-orders` ne fait que *mettre en file* à l'heure ronde, et ses jobs se rejouent à 60, 300 puis 600 s.
- **`CLOSE_GRACE_HOURS = 2`** : clôturer à minuit passé de quelques minutes gèlerait le vivier avant les courses de la dernière heure. La clôture mint une dernière fois avant le gel.

Démarrage et clôture sont journalisés avec `by: null` — l'écran d'audit les rend « Système ». La resynchronisation, elle, **n'est pas journalisée** (rejeu déterministe, pas d'argent, réversible) ; test négatif dans `AuditTrailTest`.

## Un bonus surprise tire `effectiveWinnersCount()` gagnants
`DrawService::drawSurprise()` lisait `$challenge->max_winners` — **une colonne qui n'existe pas** (le schéma porte `population_max` et `winners_count`). L'attribut valait donc toujours `null`, retombait sur `?? 1`, et tout bonus surprise ne désignait qu'un gagnant pendant que l'écran en annonçait trois, lui qui lit `Challenge::effectiveWinnersCount()`. Le nombre de gagnants se demande à cette méthode, jamais à une colonne lue à la main : elle seule sait qu'une attribution unique vaut 1 quel que soit `population_max`. Couvert par `DrawServiceTest`, qui n'exerçait pas ce chemin du tout.

## Le nombre de participants se dérive des courses
`challenges.participants_count` a été **supprimé**. Rien ne le tenait hors du seeder et de la fabrique : en production la colonne restait nulle, et les deux écrans qui la lisaient annonçaient « 0 participant » sur un challenge que tout le parc courait — un chiffre faux, qui se lisait comme un échec de participation.

`Challenge::participantsCount()` compte les conducteurs distincts ayant terminé une course sur la période ; `withParticipantsCount()` l'ajoute en colonne calculée pour une liste, et l'accesseur la réutilise si elle est là. Ne pas réintroduire de compteur stocké : puisque participer c'est avoir roulé, il n'y a rien à tenir à jour, et un compteur figé mentirait dès la course suivante.

`eligibles_count` reste, lui : c'est la prévision affichée à la création (`Wizard::estimatedEligibles()`, une heuristique assumée), pas un compte.
