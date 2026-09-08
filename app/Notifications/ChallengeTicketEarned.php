<?php

namespace App\Notifications;

use App\Models\Challenge;
use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * « Vous venez de gagner un ticket. »
 *
 * Un envoi par lot minté, et non par ticket : une passe de rattrapage en
 * franchit plusieurs d'un coup, et trois notifications pour trois tickets
 * gagnés dans la même seconde n'apprennent rien de plus qu'une.
 *
 * `afterCommit()` : la charge utile cite un nombre de tickets détenus, elle ne
 * doit pas partir avant que la transaction qui les écrit soit close — sinon un
 * échec au commit laisserait un conducteur prévenu d'un ticket inexistant.
 * Posé par le constructeur et non par une propriété : `Queueable` déclare déjà
 * `$afterCommit` sans type, et le redéclarer typé rend la classe incompatible
 * avec son propre trait — erreur fatale au chargement, silencieuse sous Pest.
 */
class ChallengeTicketEarned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Challenge $challenge,
        private int $earned,
        private int $held,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', PushChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'challenge_ticket',
            'category' => 'challenge',
            'title' => $this->earned > 1
                ? "{$this->earned} nouveaux tickets"
                : 'Nouveau ticket',
            'body' => $this->body(),
            'challenge_id' => $this->challenge->getKey(),
            'tickets_earned' => $this->earned,
            'tickets_held' => $this->held,
            'deeplink' => 'wigo://challenges/'.$this->challenge->getKey(),
        ];
    }

    private function body(): string
    {
        $tickets = $this->held > 1
            ? "{$this->held} tickets"
            : "{$this->held} ticket";

        return $this->earned > 1
            ? "Vous avez gagné {$this->earned} tickets pour « {$this->challenge->name} ». Vous en détenez {$tickets} au total."
            : "Vous avez gagné un ticket pour « {$this->challenge->name} ». Vous en détenez {$tickets} au total.";
    }
}
