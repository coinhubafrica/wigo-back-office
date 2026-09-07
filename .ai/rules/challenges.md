---
paths:
  - 'app/Http/Controllers/Api/V1/ChallengeController.php,app/Http/Resources/DriverChallengePayload.php,app/Services/Challenges/**'
---

# Challenges

## Le règlement d'un challenge tient sur la ligne, servi par URL signée
Un challenge porte **un seul** règlement, en colonnes sur `challenges` (`rules_document_disk|path|name|mime|size|uploaded_at`) — pas de table dédiée : il n'y a rien à collectionner et un remplacement écrase le précédent (et supprime l'ancien fichier du disque). Ne pas introduire de `challenge_documents`.

Fichier sur le disque privé `local`. Deux portes, jamais le chemin de stockage : `api.v1.challenges.rules` (middleware `signed`, URL d'une heure posée par `DriverChallengePayload::rulesDocument()`) et `bo.challenges.rules-document` (session + permission du module). Les deux **résolvent le modèle à la main** et répondent 403 pour un challenge inconnu *comme* pour un challenge sans règlement — l'écart 403/404 dirait quels challenges existent. Le 404 ne subsiste qu'après autorisation, fichier absent du disque.

Piège : l'enveloppe d'erreur de `bootstrap/app.php` aplatit **tout** 404 sur `__('api.not_found')`. Un message passé en 3e argument d'`abort_unless` ne ressort jamais — ne pas ajouter de clé de langue pour ça (elle serait morte, comme `api.shop.document_missing`).

Route back-office déclarée **avant** `challenges/{challenge}`, sinon la route de détail l'absorbe.

`challenges.manage-rules` / gate `manageChallengeRules` : geste à part du module, car les conducteurs se fondent sur ce document pour savoir ce qui leur est promis. Joindre et retirer sont journalisés (`challenge.rules_attached` / `challenge.rules_removed`), le retrait **avant** le `delete()`.

`ticketing.tickets` liste les tickets du conducteur (`{id, date, range_number}`, du plus ancien au plus récent). `range_number` est `null` jusqu'au gel du vivier. La liste sert aussi de compteur : `tickets_held` vaut `count($tickets)` — l'ancien `ticketsHeld()` a été retiré, ne pas rétablir une 2e requête.
