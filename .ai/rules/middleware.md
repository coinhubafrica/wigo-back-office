---
paths:
  - app/Http/Middleware/EnsureIdempotentRequest.php
---

# Middleware

## Idempotence des écritures mobiles
`Idempotency-Key` (UUID) obligatoire sur `POST /shop/orders` — 422 sans. Trois branches : clé inconnue → la requête s'exécute et la réponse est enregistrée si 2xx ; clé connue + même empreinte de corps → la réponse enregistrée est rendue telle quelle, le contrôleur n'est pas appelé (une commande, un décrément, le même `pickup_code`) ; clé connue + corps différent → 409, rien créé.

Une clé périmée (24 h) se comporte comme absente, **mais sa ligne subsiste et `key` est unique** : l'enregistrement utilise `updateOrCreate`, jamais `create`. Un test couvre ce cas.

Seules les réponses 2xx consomment la clé : une commande refusée (référence inconnue ou fermée à la commande) laisse la clé libre.

Générique — `POST /wallet/recharges` le réutilisera sans modification. Purge quotidienne dans `routes/console.php` (`idempotency:prune-keys`), sur le modèle d'`otp:prune-codes`.
