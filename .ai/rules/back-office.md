---
paths:
  - 'app/Http/Controllers/BackOffice/**'
---

# Back Office

## Fichiers privés : résolution manuelle et 403 uniforme, jamais de liaison de route
Les contrôleurs qui servent un fichier d'un disque privé (`DriverPhotoController`, `MessageAttachmentController`) **résolvent le modèle à la main** (`::query()->find($id)`), sans liaison de route implicite.

Raison : la liaison répond 404 sur un identifiant inconnu et le contrôleur 403 sur un accès refusé. L'écart entre les deux codes dit quels identifiants existent — un portrait de conducteur ou une carte grise se laissent alors énumérer. Tout ce qui n'est pas servi répond **403 avec le même corps** : inconnu, orphelin, sans portrait. Le 404 ne subsiste **qu'après autorisation**, pour un fichier absent du disque (anomalie de stockage, qui ne révèle rien).

Même raisonnement que `Api\V1\SupportController::downloadAttachment`, qui scope la pièce au fil du conducteur — garder les trois en phase.

`bo.drivers.photo` accepte **`module.drivers|module.support-requests`** (spatie lit le `|` comme un « ou », via `canAny`) : le fil du support pointe cette route pour ses avatars, et la borner aux seuls Conducteurs cassait l'image d'un agent qui ne fait que du support.

Pas de garde par enregistrement : aucun agent n'est rattaché à un sous-ensemble de conducteurs ni de fils — la liste montre tout le parc à qui porte le module. Une vérification « ce conducteur est-il le vôtre ? » n'aurait rien à vérifier. Si un tel rattachement apparaît un jour, c'est ici qu'il se branche.
