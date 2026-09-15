---
paths:
  - 'app/Notifications/**,app/Listeners/ClearDeadFcmToken.php'
---

# Listeners

## Push FCM : canal conditionné aux identifiants, et jetons morts effacés par événement
Le push passe par `laravel-notification-channels/fcm` ; il n'y a plus de `PushSender` ni de `PushChannel` maison.

Une notification poussée utilise le trait `BuildsFcmMessage` et rend `$this->pushedChannels()` dans `via()` — jamais `['database', FcmChannel::class]` en dur. Sans `FIREBASE_CREDENTIALS`, `kreait` lève à la *résolution* du service, pas à l'envoi : retenir le canal ferait échouer l'appelant (webhook Wave, job de recharge) pour un simple réveil. `pushedChannels()` écarte le canal en amont ; la ligne en base part dans tous les cas.

`toFcm()` vient du trait et reprend `toArray()` : la notification se décrit une fois. Ne jamais appeler `->notification()` — les messages sont data-only, c'est Flutter qui affiche. `FcmMessage::data()` lève sur toute valeur non-chaîne, d'où `stringify()` avant.

Les jetons morts sont effacés par `ClearDeadFcmToken` sur `NotificationFailed` (découvert automatiquement). Seuls `messageWasSentToUnknownToken()` et `messageTargetWasInvalid()` effacent : une panne Firebase est aussi un échec, mais l'effacer coûterait son push à tout le parc. `messageWasInvalid()` est exclu — message malformé = bug de notre côté.

En test : `fakeFcm()` (helper de `tests/Pest.php`) substitue `Messaging` et renseigne les identifiants, sinon le canal est écarté et rien n'est envoyé.
