---
paths:
  - 'app/Services/Cnps/**,app/Http/Resources/CnpsStatementPayload.php,app/Livewire/Cnps/**'
---

# Livewire Cnps

## L'excédent d'un mois CNPS se reporte sur le suivant, calculé jamais stocké
Décision de septembre 2026, qui **remplace** la note « pas de report » de la règle CNPS précédente (`is_carry_over` / `source_period` restent absents de la table — le report est calculé, pas stocké).

Ce qui est versé au-delà de la référence d'un mois solde le mois suivant, de proche en proche : 15 000 en août sur une référence de 9 000 = août payé + 6 000 d'avance sur septembre. `CnpsStatementService::allocateWithCarryOver()` fait la cascade, sur les périodes triées chronologiquement (le relevé les liste à l'envers — ne pas supposer l'ordre du tableau).

Deux garde-fous à ne pas « simplifier » : un mois **sans référence** n'absorbe rien et laisse passer l'excédent intact (aucun repère pour dire « soldé ») ; le report **ne remonte jamais** vers un mois antérieur — un arriéré se règle en déclarant son propre mois, avec une `payment_date` postérieure.

Le report répond à la référence de **chaque** mois : 6 000 d'avance ne soldent pas un septembre passé à 12 000.

`declared_amount` reste la somme saisie sur le mois (ce que le conducteur reconnaît avoir versé) ; `covered_amount` est ce qui lui est imputé. `remaining` / `progress` / `statusFor()` se jugent tous sur **`covered_amount`**, pas sur `declared_amount` — leur passer la somme brute réafficherait « 167 % » et raterait un mois soldé par une avance.

Back-office : le filtre d'état de `Livewire\Cnps\Index` ne se compare **plus en SQL**. Le report dépend de toute la chronologie, qu'une sous-requête par mois ne peut pas voir ; un filtre SQL et une pastille rendue en PHP se contrediraient (mois soldé par une avance affiché « Payé » mais capté par « En retard »). L'état est résolu en PHP (`idsMatchingState`) sur une fenêtre de 13 mois (`CARRY_WINDOW_MONTHS`). Un test l'assure : filtre et pastille doivent rester d'accord.
