---
paths:
  - 'app/Livewire/Users/**'
---

# Users

## Utilisateurs et rôles : droits hérités verrouillés, comptes désactivés jamais supprimés
Deux écrans : `bo.users` (comptes) et `bo.users.roles` (rôles + matrice). La permission du module ouvre la **lecture** ; écrire demande en plus `manageUsers` / `manageRoles` (`Gate::authorize` dans chaque action).

**Hérité ≠ direct.** Un droit qu'un rôle donne s'affiche coché-verrouillé avec le rôle nommé (« via Gestionnaire catalogue ») et `save()` le retire des droits directs (`directGrants()`). Sinon un droit enregistré deux fois survivrait au retrait du rôle : la personne garderait un pouvoir qu'on croyait lui avoir ôté. Pour retirer un droit hérité, on ôte le rôle.

**Cohérence accès/action** (`Roles::togglePermission`) : cocher une action donne l'accès au module, décocher l'accès emporte ses actions. Une action sans son module est une case cochée sans écran où s'exercer.

**Un compte ne se supprime pas**, il se désactive : `audit_logs`, requêtes traitées et déclarations CNPS pointent vers `users.id`. On ne peut pas se désactiver soi-même. `EnsureUserIsActive` déconnecte au prochain accès.

**Mots de passe** : générés (jamais saisis — laisser choisir invitait à réutiliser le même partout), affichés une seule fois, jamais écrits au journal (seul `password_issued: true` l'est). Toute écriture passe par `AuditLog::record` ; l'avant/après n'est journalisé que pour les droits et l'activation, une faute de frappe sur un nom n'encombre pas le journal.

Après `syncPermissions` sur un rôle : `app(PermissionRegistrar::class)->forgetCachedPermissions()`, sinon la session en cours garde ses anciens droits.
