---
paths:
  - 'app/Enums/Permission.php,app/Providers/AppServiceProvider.php'
---

# Enums Providers

## Les droits vivent dans l'énumération Permission, pas dans des noms de rôle
`App\Enums\Permission` est le catalogue complet des droits : les accès aux modules (`module.*`, un cas par `BackOfficeModule`) et les actions sensibles (`shop.manage-catalogue`, `recharges.reconcile`, `users.manage`…).

Règle : **aucun portail ne teste un nom de rôle**. `Gate::define(...)` lit `$user->can(Permission::X->value)`. Les rôles s'administrent à l'écran (« Utilisateurs et rôles ») — un `hasRole('direction')` en dur figeait une décision d'organisation dans le code et rendait la matrice des rôles mensongère : on cochait une case sans effet.

`BackOfficeModule::permission()` passe par `Permission::from()` : un module ajouté sans son cas de permission lève au premier appel, plutôt que de rendre un 403 silencieux à tout le monde. `tests/Feature/BackOffice/PermissionCatalogueTest.php` garde la cohérence des deux énumérations et vérifie que chaque portail se résout par permission.

Ajouter un droit = ajouter le cas + `belongsTo()`/`label()` + le seeder + **une migration qui l'accorde aux rôles déjà en base** (`RolePermissionSeeder` ne synchronise qu'à la création d'un rôle, cf. la règle des véhicules). Voir `2026_09_04_160000_grant_action_permissions_to_existing_roles.php`.
