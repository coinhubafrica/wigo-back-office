---
paths:
  - 'app/Livewire/Audit/**,app/Enums/AuditAction.php,app/Support/AuditLogFilter.php,app/Models/AuditLog.php'
---

# Support Models

## Journal d'audit : phrase figée, catalogue souple, filtre partagé
`AuditLog::summary` est une phrase française **figée à l'écriture** : l'écran et le CSV l'affichent verbatim, sans jamais la recomposer depuis `action` + `subject`. C'est ce qui permet à une ligne dont la cible a été supprimée (`role.deleted` n'enregistre volontairement aucun sujet) de rester lisible.

`AuditAction` est un catalogue **souple** : `AuditLog::record()` garde `string $action`, la colonne n'a aucune contrainte, et la lecture passe par `AuditAction::labelFor()` / `badgeClassesFor()` (`tryFrom`, repli sur le slug brut). La table est en ajout seul et jamais purgée — une ligne écrite par un code retiré depuis doit s'afficher, pas faire tomber la page. Ne pas retyper le paramètre en enum. `tests/Unit/Enums/AuditActionTest.php` balaie `app/` et échoue sur tout `action: '…'` en dur.

Ne pas dériver les options de filtre d'un `distinct('action')` : sur une base neuve la barre s'ouvre quasi vide et fait croire le journal cassé.

`AuditLogFilter` est l'unique définition du filtrage, partagée par l'écran et `AuditExportController` : un export qui rend autre chose que ce qui était affiché ne prouve rien. `agent = 'system'` est le jeton des écritures d'automate (`user_id` nul).

Pas d'eager-load de `subject` (morph sur ~30 types) ; `orderByDesc('occurred_at')` puis `orderByDesc('id')` — `occurred_at` est à la seconde et un geste en rafale écrit plusieurs lignes dans la même.

`Audit/Index` est en lecture seule : il n'a aucun `Gate::authorize` et **ne doit pas** figurer dans le `$writers` de `PermissionCatalogueTest`. Le seul geste écrivant est l'export (`audit.export`, portail dans le contrôleur pour que le 403 soit uniforme).

Le bouton d'export est une `<a href>` et non un `x-button` : `<x-slot:actions>` est rendu hors de la racine Livewire, donc aucun `wire:*` n'y fonctionne — d'où les filtres transportés en querystring.
