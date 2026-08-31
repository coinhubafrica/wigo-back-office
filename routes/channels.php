<?php

use App\Enums\BackOfficeModule;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Canaux de diffusion
|--------------------------------------------------------------------------
|
| Deux gardes arrivent ici : la session du back-office (`/broadcasting/auth`)
| et le jeton Sanctum du mobile (`/api/broadcasting/auth`). On discrimine sur
| le type du modèle, jamais sur la garde — un `Driver` n'a pas de rôles, un
| `User` n'est jamais un conducteur.
|
| `Broadcast::channel()` déclare une règle d'accès. Ne pas confondre avec
| `Broadcast::private()`, qui diffuse un évènement anonyme et n'enregistre
| rien — l'abonnement serait alors refusé sans que rien ne le signale.
|
| Les canaux sont privés côté client (préfixe `private-`) : un canal de
| présence publierait la liste de ses membres, et un conducteur n'a pas à
| savoir quels agents sont connectés.
|
*/

/**
 * Le fil d'un conducteur : lui-même, et les agents habilités au module.
 */
Broadcast::channel('conversation.{conversation}', function (User|Driver $actor, string $conversation): bool {
    // `find` et non `findOrFail` : un identifiant inconnu doit répondre 403,
    // pas 500, et ne renseigne alors personne sur ce qui existe.
    $found = Conversation::query()->find($conversation);

    if ($found === null) {
        return false;
    }

    return $actor instanceof Driver
        ? $found->driver_id === $actor->getKey()
        : $actor->can(BackOfficeModule::SupportRequests->permission());
});

/**
 * La file de traitement : nouveaux messages, compteurs. Agents seulement.
 */
Broadcast::channel('support-queue', fn (User|Driver $actor): bool => $actor instanceof User
    && $actor->can(BackOfficeModule::SupportRequests->permission()));

/**
 * Canal personnel du conducteur : diffusions et messages hors fil ouvert.
 */
Broadcast::channel('driver.{driver}', fn (User|Driver $actor, string $driver): bool => $actor instanceof Driver
    ? $actor->getKey() === $driver
    : $actor->can(BackOfficeModule::SupportRequests->permission()));
