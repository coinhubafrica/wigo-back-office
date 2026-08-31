---
paths:
  - 'tests/**'
---

# Tests

## Pest : pas de classe, et les helpers globaux ne voient pas $this
La suite est en Pest. `tests/Pest.php` applique déjà `TestCase` partout et `LazilyRefreshDatabase` à tout `Feature` : ne pas les redéclarer dans un fichier de test.

Piège principal : les helpers privés d'une ancienne classe deviennent des fonctions globales. Deux conséquences.

1. Leur nom est global à toute la suite — deux fichiers ne peuvent pas déclarer `user()`. Préfixer par le sujet du fichier (`cnpsUser()`, `shopOrdersUser()`).
2. `test()` rend le cas de test courant, mais **seuls les membres publics** sont joignables : `test()->app` échoue (`$app` est `protected`), utiliser `app()`. `test()->get()` / `test()->assertX()` fonctionnent.

Dans une fermeture `it(...)`, `$this` reste le cas de test : `$this->assertSame()`, `$this->actingAs()` s'écrivent normalement. On ne réécrit pas les assertions PHPUnit en `expect()` sans raison.

`setUp`/`tearDown` deviennent `beforeEach`/`afterEach`, sans l'appel `parent::`. Un `#[DataProvider]` devient `->with([...])`.

Lancer avec `php artisan test --parallel` (~27 s contre ~59 s en série) ; `composer test` le fait déjà.
