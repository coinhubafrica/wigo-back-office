<?php

namespace App\Listeners;

use App\Models\Driver;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Arr;
use Kreait\Firebase\Messaging\SendReport;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Efface un jeton FCM que Firebase a refusé.
 *
 * Le conducteur a désinstallé ou réinstallé l'application : garder le jeton
 * ferait échouer chaque envoi suivant. Le canal ne touche pas la base, il
 * signale l'échec par `NotificationFailed` — c'est ici qu'on en tire la
 * conséquence.
 */
class ClearDeadFcmToken
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->channel !== FcmChannel::class) {
            return;
        }

        $report = Arr::get($event->data, 'report');

        if (! $report instanceof SendReport || ! $this->tokenIsDead($report)) {
            return;
        }

        $driver = $event->notifiable;

        if (! $driver instanceof Driver) {
            return;
        }

        /*
        | Le jeton du rapport doit être encore celui du conducteur : entre
        | l'envoi et l'échec, le mobile a pu en enregistrer un neuf, et
        | l'effacer reviendrait à le rendre muet pour de bon.
        */
        if ($driver->fcm_token !== $report->target()->value()) {
            return;
        }

        $driver->forceFill(['fcm_token' => null])->save();
    }

    /**
     * Seul un jeton mort se voit effacé.
     *
     * Une panne de Firebase est aussi un échec, mais le jeton n'y est pour
     * rien : l'effacer coûterait son push à tout le parc le jour d'une
     * indisponibilité. `messageWasInvalid()` est volontairement absent — un
     * message malformé est un bug de notre côté, pas un appareil perdu.
     */
    private function tokenIsDead(SendReport $report): bool
    {
        return $report->messageWasSentToUnknownToken()
            || $report->messageTargetWasInvalid();
    }
}
