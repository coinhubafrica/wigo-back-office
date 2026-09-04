---
paths:
  - 'app/Livewire/**'
---

# Livewire

## Toute méthode Livewire qui écrit appelle Gate::authorize
L'accès au module (`module.*`) n'ouvre que la **lecture**. Chaque geste mutant porte sa permission de `App\Enums\Permission` et son portail : `Gate::authorize('...')` en **première instruction** de la méthode, avant toute validation ou garde de nullité.

`tests/Feature/BackOffice/PermissionCatalogueTest.php` est le garde-fou : il échoue si un composant qui écrit ne contient aucun `Gate::authorize`, et si une permission d'action n'est lue par aucun `Gate::define`. Une permission sans portail est une case à cocher sans effet — la matrice des rôles promettrait un droit que rien ne lit.

Le découpage suit les **conséquences**, pas les verbes CRUD : répondre à un ticket (`support.handle`) ≠ l'écarter sans réponse (`support.dismiss`) ; rédiger une campagne (`campaigns.manage`) ≠ la diffuser (`campaigns.send`) ; préparer une commande (`shop.fulfil-orders`) ≠ l'annuler (`shop.cancel-order`, qui peut rembourser) ; écrire au catalogue (`shop.manage-catalogue`) ≠ faire avancer une commande. Le cycle de vie d'un challenge est éclaté geste par geste (`create`, `close-period`, `draw`, `regenerate-seed`, `credit`) — `regenerate-seed` change le hasard après le gel du vivier et reste au seul arbitre.

**Jamais `hasRole()` / `hasAnyRole()`**, ni dans une méthode, ni dans un helper lu par la vue (`canManageBonus()` masquait des boutons que les portails autorisaient), ni pour choisir un statut (`Wizard::save` pose `PendingApproval` selon `approveSurpriseChallenge`, pas selon le nom du rôle).

Exceptions délibérées, non gardées : `assignToMe` (tout agent reprend son propre ticket) et `select()` (horodatage de lecture, pas une décision).

Les gestes irréversibles ou qui touchent à l'argent sont **journalisés** (`AuditLog::record`) : suspension, envoi de campagne, tirage, republication de graine, crédit de lot, publication/suppression d'annonce.

## Journaliser : l'irréversible, pas chaque enregistrement — et jamais un secret
Un geste mérite une ligne d'audit quand une personne raisonnable pourrait le contester plus tard : il a déplacé de l'argent, coupé un revenu, changé qui peut quoi, atteint tout le parc, ou il est irréversible. Un journal trop plein ne se lit pas, et un journal illisible ne prouve rien.

**Secrets** : les enregistrements de réglages porteurs de clés ne journalisent que le **nom** des champs remplacés (`Settings\Index::replacedSecretFields()`), jamais leur valeur — sinon l'écran d'audit, et l'export qui en sort un fichier, deviennent le point de fuite. Champ laissé vide = clé conservée = rien à journaliser. Les valeurs non secrètes (plafonds, barème OTP, `base_url`/`park_id` Yango) sont journalisées en avant/après, et seulement si elles ont bougé.

**Mouvement, pas enregistrement** : `shop.price_changed` n'écrit que si `unit_price` change ; renommer une référence laisse la ligne comme preuve. Une **suppression dure** se journalise toujours (produit, lot, réponse type, rôle, annonce) et **avant** l'appel à `delete()`, car après il ne reste rien à citer.

**Délibérément non journalisés** (ne pas « corriger » par zèle) : `testFleet` (sonde en lecture seule), `Announcements::reorder`/`duplicate` (ordre cosmétique / copie inactive), `Campaigns::saveDraft` (un brouillon n'atteint personne), les réponses de support `send`/`sendTriageReply` (le `Message` est sa propre trace), `createTicket`/`resolve`/`recategorise` (l'état de la ligne suffit), les transitions de commande `mark*` (contraintes et horodatées sur la commande), `Templates::save`/`toggle`, le référentiel marques/modèles. Couvert par des tests négatifs dans `AuditTrailTest`.

`Shop\Orders::cancelOrder` journalise **dans la méthode**, pas dans `apply()` : `apply()` reste le point unique d'autorisation, et y poser une ligne en mettrait une sur les cinq transitions. Portail et journal n'ont pas la même granularité.

Le trait `app/Livewire/Concerns/InteractsWithCurrentUser` fournit `actor()` (garde `web` nommée, lève si absent) — ne pas réintroduire un helper local ni `/** @var User */ auth()->user()`.
