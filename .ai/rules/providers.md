---
paths:
  - app/Providers/AppServiceProvider.php
---

# Providers

## Carte de morph stricte et exhaustive
`Relation::enforceMorphMap()` est appliquée dans `configureModels()`. Toutes les colonnes `*_type` portent un alias court — `staff`, `driver`, `transaction` — jamais un nom de classe. Cela vaut aussi pour les morphs de Laravel et de spatie : `notifications.notifiable_type`, `model_has_roles.model_type`, `personal_access_tokens.tokenable_type`, `audit_logs.subject_type`.

Conséquence à connaître : un `assertDatabaseHas` sur une de ces colonnes doit attendre `'staff'`, pas `User::class`.

La variante stricte lève sur un modèle absent de la carte. C'est voulu — `AuditLog::record()` accepte n'importe quel `Model` — donc **tout nouveau modèle doit être ajouté à la carte**. `tests/Feature/MorphMapTest.php` échoue sinon.

L'alias de `User` est `staff` et non `user` : dans une application où le conducteur est lui aussi un utilisateur, « user » ne désignerait rien.

## Carte de morph stricte et exhaustive
`Relation::enforceMorphMap()` est appliquée dans `configureModels()`. Toutes les colonnes `*_type` portent un alias court plutôt qu'un nom de classe — y compris les morphs de Laravel et de spatie : `notifications.notifiable_type`, `model_has_roles.model_type`, `personal_access_tokens.tokenable_type`, `audit_logs.subject_type`, `messages.sender_type`.

**L'alias est toujours le nom de la classe en snake_case**, sans exception : `User` → `user`, `ShopOrder` → `shop_order`. Un alias inventé se retient mal et finit par désigner autre chose que ce qu'il dit. `tests/Feature/MorphMapTest.php` vérifie la règle sur chaque entrée.

Conséquence à connaître : un `assertDatabaseHas` sur une de ces colonnes attend `'user'`, pas `User::class`.

La variante stricte lève sur un modèle absent de la carte. C'est voulu — `AuditLog::record()` accepte n'importe quel `Model` — donc **tout nouveau modèle doit être ajouté à la carte**, sinon le test échoue.

Dans le code, préférer `$model->getMorphClass()` à l'alias écrit en dur : renommer une entrée ne laisse alors rien derrière.
