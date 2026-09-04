---
paths:
  - app/Support/NavigationBadges.php
---

# App Support

## Pastilles de la barre latérale : un seul point d'ajout
Les compteurs affichés en pastille dans `layouts.app` viennent tous de `NavigationBadges::counts()`. Pour en ajouter un, ajouter une entrée à ce tableau — ne pas compter dans la vue ni dans un composant Livewire.

Une pastille compte le travail *en attente d'une action*, pas les lignes du module : tickets `live()` pour Requêtes, commandes `Ordered` pour Commandes. Un compte à zéro rend `null` et n'affiche rien.

`counts()` est mémoïsé car la barre latérale est rendue à chaque page ; le service est résolu une fois dans le bloc `@php` du layout.
