---
paths:
  - 'app/Services/Wave/**'
---

# Wave

## Deux comptes Wave : le compte se déduit du type, jamais d'un argument
Le back-office opère deux comptes Wave : `WaveAccount::Shop` (commandes) et `WaveAccount::Topup` (recharges Yango). Clés et secrets vivent dans `WaveSettings` (chiffrés, réglables à l'écran), pas en `.env` — les variables `WAVE_BASE_URL`/`WAVE_API_KEY`/`WAVE_WEBHOOK_SECRET` ont été retirées, seul `WAVE_DRIVER` reste.

- `createCheckoutSession()` choisit le compte via `SaloonWaveClient::accountFor($transaction->type)`, jamais par un argument d'appelant : aucun chemin de code ne doit pouvoir encaisser une commande sur le compte de recharge par omission.
- Un webhook arrive sans étiquette : c'est le segment d'URL (`webhooks/wave/{account}`) qui désigne le secret à vérifier. Ne jamais lire le compte dans le corps, et ne jamais essayer les deux secrets — cela ne prouverait pas de quel compte vient le paiement.
- En test, l'en-tête `Authorization` est posé dans `boot()` du connecteur : il n'apparaît pas sur le `Request` que rejoue `assertSent()`. Vérifier le compte débité via `$mock->getLastPendingRequest()`.

## Deux comptes Wave : le compte se déduit du type, jamais d'un argument
Le back-office opère deux comptes Wave, séparés jusqu'au bout : `WaveShopSettings` (groupe `wave_shop`, commandes) et `WaveTopupSettings` (groupe `wave_topup`, recharges Yango), tous deux dérivés de `WaveAccountSettings` (`api_key` + `webhook_secret`, chiffrés). Un panneau et un enregistrement par compte dans « Paramètres » — les régler d'un seul geste invitait à les confondre. Les variables `WAVE_BASE_URL`/`WAVE_API_KEY`/`WAVE_WEBHOOK_SECRET` ont été retirées, seul `WAVE_DRIVER` reste.

- `WaveAccount::settings()` est le seul endroit qui relie un compte à sa classe de réglages : les appelants passent un `WaveAccount`.
- `createCheckoutSession()` choisit le compte via `SaloonWaveClient::accountFor($transaction->type)`, jamais par un argument d'appelant : aucun chemin de code ne doit pouvoir encaisser une commande sur le compte de recharge par omission.
- Un webhook arrive sans étiquette : c'est le segment d'URL (`webhooks/wave/{account}`) qui désigne le secret à vérifier. Ne jamais lire le compte dans le corps, et ne jamais essayer les deux secrets — cela ne prouverait pas de quel compte vient le paiement.
- En test, l'en-tête `Authorization` est posé dans `boot()` du connecteur : il n'apparaît pas sur le `Request` que rejoue `assertSent()`. Vérifier le compte débité via `$mock->getLastPendingRequest()`.
