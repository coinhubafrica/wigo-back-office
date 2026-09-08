---
paths:
  - 'app/Livewire/Cnps/**'
---

# App Livewire Cnps

## Le filtre d'état CNPS ne remonte que des identifiants, jamais le parc hydraté
`Index::idsMatchingState()` part de `searchQuery()` (recherche seule, sans colonne calculée) et `pluck('drivers.id')` : l'ancienne version chargeait tous les conducteurs avec leurs quatre `selectSub` pour n'en garder qu'une page. La référence du mois est reprise d'`allocations()` (clé `reference`), qui charge déjà la fenêtre de treize mois — même valeur que la colonne `period_reference`.

« Payé » / « Partiel » exigent un montant imputé, donc au moins une déclaration dans la fenêtre `periodsUpTo()` : ces candidats sont resserrés en base par `whereHas('cnpsDeclarations', period in fenêtre)` avant le calcul PHP. Ne pas étendre ce resserrement à « En retard » / « À déclarer » : un mois sans ligne est précisément ce qu'ils cherchent. Un test (`a payment older than the carry window…`) vérifie que la présélection suit la même fenêtre que le report.

Piège : `pluck()` sur une requête portant des `selectSub` garde leurs liaisons et casse (« Invalid parameter number ») — toujours plucker depuis la requête sans colonnes calculées.
