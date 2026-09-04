---
paths:
  - 'app/Services/Fleet/**'
---

# Fleet

## Synchronisation Yango Fleet : identifiant, adoption par téléphone, statut jamais réécrit
`FleetSyncService` (commande `fleet:sync`, job `SyncFleetJob`, planifié à l'heure) rapproche le parc Yango via Saloon (`app/Http/Integrations/Yango/`).

Décisions à ne pas redéfaire :

- **Identifiant conducteur = `driver_profile.id`**, jamais `accounts.0.id`. Le projet d'origine (alal-pro) mélangeait les deux selon le chemin de code ; `driver_profile.id` est celui que l'API attend en `contractor_profile_id`.
- **Rapprochement en trois temps** : `yango_id`, sinon téléphone normalisé E.164 (*adoption* : on pose `yango_id` sur la ligne existante, créée à l'inscription mobile), sinon création. Un profil sans téléphone exploitable est ignoré et journalisé — `drivers.phone` est requis et unique.
- **Le `status` d'un conducteur existant n'est jamais réécrit.** Une suspension est une décision du back-office (`suspension_reason`) ; Yango n'a pas à la défaire. Un conducteur créé par la synchronisation naît `Dormant` (aucune CGU acceptée).
- **Les enregistrements que Yango ne remonte plus sont signalés, jamais modifiés** : compteurs dans le résumé de la commande + `Log::warning`. Pas de désactivation automatique — une absence peut venir d'une panne Yango.
- **`FleetDirectory` lève, `FleetClient` rend `null`.** Contrat d'erreur volontairement inverse : une passe interrompue au milieu ne doit pas écrire un parc tronqué. D'où deux contrats séparés.
- `SyncFleetJob` **échoue franchement sur 401/403** (clé refusée, inutile de réessayer), remet en file sinon.

Un véhicule tient sur une seule ligne : une réaffectation déplace `driver_id` (cf. `.ai/rules/models.md`). La passe « parc » ne touche pas `driver_id`, pour ne pas détacher ce que la passe « conducteurs » vient de rattacher.
