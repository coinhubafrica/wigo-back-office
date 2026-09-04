---
paths:
  - 'app/Livewire/Vehicles/**'
---

# Vehicles

## Véhicules : écran en lecture seule, fiche courte, groupe « Parc »
Le module Véhicules (`bo.vehicles`, `bo.vehicles.show`) double la liste des conducteurs, mais en lecture seule.

- **Aucune action, jamais.** Le parc et l'affectation appartiennent à Yango (cf. `.ai/rules/models.md`) : pas de création, pas de suppression, pas de réaffectation à la main. Des tests l'assertent sur le balisage (`wire:click="assign`, `wire:submit`, `<form`) — ne pas ajouter de bouton sans revenir sur cette décision.
- **La fiche n'a ni onglets ni cartes d'indicateurs.** Rien dans le schéma ne pointe vers un véhicule (ni course, ni entretien, ni commande). Décision explicite : une fiche courte et vraie plutôt qu'une grille de tirets — c'est la leçon de « courses de la semaine » sur la fiche conducteur. Ne pas remplir la page tant qu'il n'y a pas de données réelles.
- **Filtres** : affectés / sans conducteur / hors parc. « Sans conducteur » est celui qui compte à l'exploitation — une voiture sans chauffeur ne roule pas.
- **Groupe « Parc »** : Chauffeurs et Véhicules y sont réunis, et Chauffeurs a quitté Support (qui ne garde que Requêtes). L'ordre de la barre latérale suit l'ordre des cas de `BackOfficeModule` — pour déplacer une entrée, déplacer le `case`.

**Piège, à retenir pour tout nouveau module** : `RolePermissionSeeder` ne synchronise les permissions qu'à la création d'un rôle (`wasRecentlyCreated`), pour ne pas écraser un rôle affiné à la main. Une installation existante n'hérite donc jamais d'un module ajouté ensuite — la page rend un 403 à tout le monde, y compris « direction », alors que les tests passent (base neuve). Il faut une migration qui accorde la nouvelle permission aux rôles concernés : voir `2026_09_04_141037_grant_vehicles_module_to_existing_roles.php`.
