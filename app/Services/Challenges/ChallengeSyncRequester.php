<?php

namespace App\Services\Challenges;

use App\Enums\ChallengeStatus;
use App\Jobs\SyncYangoDriverOrdersJob;
use App\Models\Challenge;
use App\Models\Driver;
use Illuminate\Support\Carbon;

/**
 * Demande un rapatriement des courses d'un conducteur, au plus une fois
 * l'heure.
 *
 * Le planificateur ne repasse qu'à l'heure ronde : un conducteur qui vient de
 * finir une course et ouvre son écran verrait un compteur en retard, et
 * conclurait que le challenge ne compte pas ses courses. Sa lecture déclenche
 * donc sa propre passe.
 *
 * Tout conducteur participe à toute tombola — il n'y a pas d'inscription à
 * vérifier. La seule condition est qu'une tombola soit ouverte : sans elle,
 * aucun ticket n'est en jeu et la passe ne servirait qu'à consommer du quota.
 */
class ChallengeSyncRequester
{
    /**
     * Fenêtre du throttle. Une heure : la même cadence que le planificateur,
     * de sorte qu'une lecture mobile double la fraîcheur sans doubler le coût.
     */
    private const THROTTLE_MINUTES = 60;

    /**
     * @return bool vrai si une passe vient d'être mise en file
     */
    public function requestFor(Driver $driver): bool
    {
        if (! is_string($driver->yango_id) || $driver->yango_id === '') {
            return false;
        }

        /*
        | Le repère est relu en base plutôt que sur l'instance reçue : celle
        | d'un conducteur qui vient d'être créé ne porte pas la colonne, et
        | `Model::shouldBeStrict()` — actif hors production — lève sur la
        | lecture d'un attribut non chargé. C'est aussi la valeur vraie qu'on
        | veut ici, pas celle d'une instance vieille de quelques requêtes.
        */
        $requestedAt = Driver::query()->whereKey($driver->getKey())->value('orders_sync_requested_at');

        if ($requestedAt !== null && Carbon::parse($requestedAt)->greaterThan(Carbon::now()->subMinutes(self::THROTTLE_MINUTES))) {
            return false;
        }

        $earliestStart = Challenge::query()
            ->where('is_ticket_based', true)
            ->where('status', ChallengeStatus::Active)
            ->min('period_start');

        if ($earliestStart === null) {
            return false;
        }

        /*
        | Le repère est posé *avant* la mise en file, et non après la passe :
        | c'est la demande qu'il faut espacer. Posé après, deux lectures
        | simultanées mettraient deux passes en file avant que la première
        | n'ait rien écrit.
        */
        Driver::query()->whereKey($driver->getKey())->update(['orders_sync_requested_at' => Carbon::now()]);

        SyncYangoDriverOrdersJob::dispatch(
            (string) $driver->getKey(),
            Carbon::parse($earliestStart)->toDateTimeString(),
            Carbon::now()->toDateTimeString(),
        );

        return true;
    }
}
