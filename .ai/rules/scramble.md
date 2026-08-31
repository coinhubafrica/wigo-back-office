---
paths:
  - 'app/Support/Scramble/**'
---

# Scramble

## Un middleware est invisible à Scramble : le documenter par une OperationExtension
Scramble déduit le contrat du code du contrôleur et ne voit jamais les middlewares. Une exigence portée par un middleware (en-tête obligatoire, code d'erreur propre) est donc absente du contrat, et le bouton d'essai de /docs/api construit une requête invalide.

`DocumentIdempotencyKey` traite ce cas pour `Idempotency-Key` : elle lit `$routeInfo->route->gatherMiddleware()` et n'ajoute l'en-tête + le 409 que si l'alias `idempotency` est présent. Toute nouvelle écriture protégée par ce middleware est documentée sans modification.

Ne pas coder en dur une liste d'URL dans ce genre d'extension — lire les middlewares de la route. Toute extension doit être enregistrée dans `config/scramble.php` (`extensions`), puis `composer docs`. `tests/Feature/Docs/ApiDocumentationTest.php` couvre le cas positif et le cas négatif (une route sans le middleware ne doit pas annoncer l'en-tête).
