---
paths:
  - 'app/Models/Challenge.php,app/Services/Challenges/ParticipantCounter.php,app/Livewire/Challenges/**'
---

# Challenges Livewire Challenges

## Le compte de participants se lie en constantes, jamais en colonnes de `challenges`
`ParticipantCounter` compte les conducteurs distincts ayant terminé une course sur `[period_start, period_end]`, bornes liées en **paramètres**. Ne pas revenir à une sous-requête corrélée (`whereColumn('yango_orders.completed_at', '>=', 'challenges.period_start')`) : MySQL ne sait pas faire d'une colonne de la ligne externe une borne d'index, le plan retombe sur la seule première colonne de `yango_orders (status, completed_at, driver_id)` et chaque ligne de la page relit tout l'historique terminé. Mesuré sur 2 M de courses : 22,6 s pour une page de 20, contre 1,3 s en constantes — `/challenges` tombait en 504 en préproduction (`upstream timed out`).

Ni `whereDate()` ni `DATE(completed_at)` : même effet, l'index est écarté. `withParticipantsCount()` hydrate l'attribut en `afterQuery()`, donc après pagination, sur les seules lignes visibles ; `ChallengeParticipantsCountTest` garde le contrat avec une assertion de journal de requêtes vide.

Une **vue SQL** ne résout rien (essayée : 8,2 s) : c'est une requête stockée, pas des lignes stockées, reconstruite en table temporaire non indexée à chaque exécution. Un cumul journalier (`driver_daily_activities`) irait plus vite encore mais **arrondit à la journée** : les tombolas courent de 14:00:55 à 14:00:55 et le compte gonflait de ~16 %. Si on y revient un jour, il faut lire le cumul pour les journées pleines et `yango_orders` pour les deux journées partielles — et d'abord combler les journées jamais synchronisées (`syncDay()` ne rejoue qu'une fenêtre de 2 jours).

Hors tombola, `Show::eligibleCount()` réutilise le compte de participants : c'est la même question. Une tombola garde son propre compte (porteurs de tickets ⊂ conducteurs ayant roulé).
