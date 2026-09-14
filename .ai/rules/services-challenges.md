---
paths:
  - 'app/Http/Controllers/Api/V1/ChallengeController.php,app/Services/Challenges/DriverProgressService.php'
---

# Services Challenges

## `meta.prizes_won` ne double pas le bloc `won`
`GET /challenges` ne liste que les challenges `Active` : le bloc `won` d'un élément de `data` ne paraît donc que sur un challenge encore en cours, alors qu'un gain se constate une fois la période finie — le challenge a alors quitté `data`. L'écran des gains du mobile serait toujours vide s'il ne lisait que `data`.

`DriverProgressService::prizesWon()` lit donc `challenge_winners` directement (l'index `(driver_id, credited_at)` est là pour ça), tous challenges confondus, et sort en `meta.prizes_won`. Ne pas le remplacer par un élargissement du filtre de statut de `data` : `data` porte ce sur quoi le conducteur peut encore agir, `meta.prizes_won` ce qu'il a déjà gagné.

Tri sur `coalesce(challenges.drawn_at, challenge_winners.credited_at)` : un gain non encore déposé n'a pas de `credited_at`, trier sur cette seule colonne renverrait les gains les plus frais en fin de liste.

`collection_note` suit la même règle que dans `won` : posée pour un lot physique seulement, `null` pour un gain en cash (crédité sur le compte Yango, rien à venir chercher).

`meta.current_week` vaut la dernière entrée de `weekly_history` mais est publié à part : le jour où la fenêtre de l'historique change, le compteur de l'écran ne bouge pas. `meta.challenge_counts` porte toutes ses clés même à zéro — ce sont les onglets de filtre, pas une liste de ce qui existe.
