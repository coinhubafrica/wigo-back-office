# Temps réel — client Flutter

> Contrat WebSocket (canaux, évènements, auth) : `/docs/api/realtime`. Cette
> page ne couvre que l'intégration Flutter.

Reverb parle le protocole Pusher : pas de SDK maison côté mobile, le paquet
[`pusher_channels_flutter`](https://pub.dev/packages/pusher_channels_flutter)
suffit.

```yaml
# pubspec.yaml
dependencies:
  pusher_channels_flutter: ^2.4.0
```

## Connexion et authentification

`onAuthorizer` plutôt que le simple `authEndpoint` : c'est un callback
appelé à chaque abonnement, donc il envoie toujours le jeton Sanctum
courant. Avec `authEndpoint` seul, le jeton serait figé au moment de
l'`init()` — un souci dès le premier renouvellement de session.

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:pusher_channels_flutter/pusher_channels_flutter.dart';

class SupportRealtime {
  final PusherChannelsFlutter pusher = PusherChannelsFlutter.getInstance();
  final String reverbKey;
  final String reverbHost;
  final int reverbPort;
  final String Function() currentAuthToken; // toujours le jeton courant

  SupportRealtime({
    required this.reverbKey,
    required this.reverbHost,
    required this.reverbPort,
    required this.currentAuthToken,
  });

  Future<void> connect() async {
    await pusher.init(
      apiKey: reverbKey,
      cluster: 'mt1', // ignoré par Reverb, le paquet exige une valeur
      useTLS: true,   // REVERB_SCHEME=https en staging/prod
      host: reverbHost,
      wsPort: reverbPort,
      wssPort: reverbPort,
      onAuthorizer: (channelName, socketId, options) async {
        final res = await http.post(
          Uri.parse('https://<api-host>/api/v1/broadcasting/auth'),
          headers: {
            'Authorization': 'Bearer ${currentAuthToken()}',
            'Accept': 'application/json',
          },
          body: {'socket_id': socketId, 'channel_name': channelName},
        );
        return jsonDecode(res.body); // {auth: "..."}
      },
      onConnectionStateChange: (current, previous) {
        // piloter le repli sur le polling depuis cet état (voir plus bas)
      },
    );

    await pusher.connect();
  }
}
```

`<api-host>` : le domaine de l'API mobile, jamais `REVERB_HOST` (celui-ci
sert uniquement à la connexion WebSocket elle-même, distincte de
l'authentification HTTP du canal). Un jeton expiré fait échouer
`onAuthorizer` avec un 401/403 — rafraîchir le jeton Sanctum d'abord,
ne pas réessayer tel quel.

## Abonnement

```dart
Future<void> listenToConversation(String conversationId) async {
  final channel = await pusher.subscribe(
    channelName: 'private-conversation.$conversationId',
  );

  await pusher.bind(
    channelName: channel.channelName,
    eventName: '.message.sent', // point devant : broadcastAs() côté serveur
    onEvent: (event) {
      final data = jsonDecode(event.data!);
      // signal seulement : rappeler GET /api/v1/support/conversation/messages
    },
  );

  await pusher.bind(
    channelName: channel.channelName,
    eventName: '.message.read',
    onEvent: (event) {
      final data = jsonDecode(event.data!);
      // {conversation_id, reader_type, read_at}
    },
  );
}
```

Préfixe `private-` à écrire explicitement : contrairement à Laravel Echo
(JS), ce paquet ne l'ajoute pas tout seul devant le nom de canal passé à
`routes/channels.php`.

## Cycle de vie

- S'abonner à l'ouverture de l'écran support (ou de l'app au premier plan) ;
  `pusher.unsubscribe(channelName: ...)` en la quittant.
- `onConnectionStateChange` signale les déconnexions : y activer le repli
  sur `GET /api/v1/support/unread` (voir « Repli sans websocket » dans
  `/docs/api/realtime`) tant que l'état n'est pas revenu à `CONNECTED`.
