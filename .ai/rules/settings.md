---
paths:
  - 'app/Settings/**'
---

# Settings

## Réglages métier en base, interrupteurs de sécurité dans config/wigo.php
Les valeurs que le métier ajuste vivent dans `app/Settings` (spatie/laravel-settings, table `settings`, migrations dans `database/settings`) : barème OTP, plafonds de recharge, et à venir le barème SLA du support.

Ce qui reste dans `config/wigo.php`, piloté par l'environnement, et qui **ne doit pas** migrer :
- `otp.expose_code` — contourne l'authentification ; `OtpService::exposesCode()` le refuse en production. Modifiable depuis une page web, ce serait une porte dérobée.
- `docs.enabled` / `docs.token` — portée déploiement.
- `terms_version` — accompagne la publication d'un document juridique.

Résoudre les réglages **au plus tard** : injection par constructeur dans un service, `app(OtpSettings::class)` ailleurs. Dans un `RateLimiter::for()`, résoudre **dans la fermeture** et jamais à l'enregistrement — sinon la table est lue au démarrage (migrations, `config:cache`) et une modification n'est plus prise en compte sans redéploiement.
