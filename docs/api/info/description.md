Contrat entre le back-office Laravel et l'application mobile **WiGO PRO**
(chauffeurs Yango en Côte d'Ivoire, opéré par At Confort Plus).

- Montants en **entiers FCFA** (XOF).
- Dates au format **ISO 8601 UTC**.
- Identifiants **ULID**.
- Messages d'erreur **en français**, prêts à afficher.
- Enveloppe unique : succès `{ message, data }`, erreur
  `{ message, errors }`. Le code HTTP porte le statut, jamais le corps.
- Pagination **curseur** : `meta.next_cursor` et `links.next`,
  `per_page` ≤ 50.
- Authentification par **jeton Sanctum** (habilitation `mobile:*`) obtenu
  via `POST /auth/otp/verify`.

Messagerie en direct (WebSocket) : voir [`/docs/api/guides/realtime`](/docs/api/guides/realtime).
