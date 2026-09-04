<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use RuntimeException;

/**
 * L'agent qui agit à l'écran.
 *
 * Les composants du back-office vivent tous derrière `auth` + `user.active` :
 * l'utilisateur est là, et c'est un `User`. Le trait porte cette affirmation
 * une fois, au lieu de la répéter dans neuf composants — sous trois noms
 * (`actor`, `currentUser`, `agent`) et trois idiomes (`auth()->user()`,
 * `Auth::user()`, `auth('web')->user()`), dont aucun ne disait pourquoi le
 * transtypage était sûr.
 *
 * La garde est **nommée** : le projet en a deux (`web` pour les agents,
 * `drivers` pour les conducteurs), et `auth()->user()` suit celle de la
 * configuration.
 *
 * Le contrôle lève au lieu d'un `@var` nu : le transtypage mentait à l'analyse
 * statique, et si la garde changeait un jour la panne surgirait au fond de
 * `AuditLog::record()` plutôt qu'à sa source. Même choix que `ResolvesDriver`.
 *
 * Le trait n'expose **que** cet accesseur, pas d'enveloppe `audit()` :
 * `AuditLog::record()` est le point d'entrée unique du journal, et une
 * enveloppe cacherait la phrase figée du `summary` — la propriété la plus
 * importante de cette table — en invitant à la dériver du slug.
 */
trait InteractsWithCurrentUser
{
    protected function actor(): User
    {
        $user = auth('web')->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Un écran du back-office exige un agent authentifié.');
        }

        return $user;
    }
}
