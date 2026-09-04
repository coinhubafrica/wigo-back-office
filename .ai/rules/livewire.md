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
