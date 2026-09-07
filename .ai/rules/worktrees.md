---
paths:
  - '.claude/worktrees/**'
---

# Worktrees

## Worktree : partager .env et node_modules, jamais vendor
Un worktree neuf n'a ni `vendor`, ni `node_modules`, ni `.env`, et la suite tombe sans eux (83 échecs `ViteManifestNotFoundException` sans `public/build`).

Ce qui se partage par lien symbolique vers le dépôt principal : **`.env`** et **`node_modules`** (chemins indépendants ; `npm run build` fonctionne au travers). Les quatre chemins sont dans `.gitignore`, rien ne part au commit.

**`vendor` ne se partage pas.** L'autoloader de Composer calcule `$baseDir = dirname(dirname(__DIR__))` : au travers d'un lien, `__DIR__` résout vers le dépôt principal, donc `App\` pointe sur le `app/` du principal et le code du worktree n'est jamais chargé. Symptôme trompeur : `Target class [config] does not exist` sur tous les tests — ce n'est pas une carte d'autoload périmée, c'est le mauvais dépôt qui démarre. Faire `composer install` dans le worktree (~216 Mo), puis `npm run build` pour le manifeste Vite.
