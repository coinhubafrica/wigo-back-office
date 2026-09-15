<?php

namespace App\Notifications;

use App\Models\ChallengeWinner;
use App\Notifications\Concerns\BuildsFcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * « Vous avez gagné. »
 *
 * Le pendant de `ChallengeTicketEarned`, et plus important que lui : un
 * challenge tiré est sorti de sa période, donc il ne figure plus dans
 * `GET /api/v1/challenges` — qui ne liste que les challenges `active`. Sans
 * cette ligne, le gagnant ne peut le constater qu'en déroulant la liste
 * secondaire des gains passés.
 *
 * Un lot physique se retire au siège : la consigne voyage dans la charge
 * utile, sinon elle n'est jamais lue.
 */
class ChallengeWon extends Notification implements ShouldQueue
{
    use BuildsFcmMessage, Queueable;

    public function __construct(private ChallengeWinner $winner) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->pushedChannels();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $challenge = $this->winner->challenge;
        $prizeName = $this->winner->prize->name ?? $challenge->prize?->name;

        return [
            'type' => 'challenge_won',
            'category' => 'challenge',
            'title' => 'Vous avez gagné !',
            'body' => $this->body($challenge->name, $prizeName),
            'challenge_id' => $challenge->getKey(),
            'challenge_name' => $challenge->name,
            'prize_name' => $prizeName,
            'amount' => $this->winner->amount,
            'rank' => $this->winner->rank,
            // La consigne de retrait n'accompagne qu'un lot physique : une
            // prime en argent arrive sur le solde, il n'y a rien à retirer.
            'collection_note' => $prizeName === null ? null : __('api.prize_collection_note'),
            'deeplink' => 'wigo://challenges',
        ];
    }

    private function body(string $challengeName, ?string $prizeName): string
    {
        if ($prizeName !== null) {
            return "Vous remportez {$prizeName} au challenge « {$challengeName} ». ".__('api.prize_collection_note').'.';
        }

        $amount = number_format((int) $this->winner->amount, 0, ',', ' ');

        return "Vous remportez {$amount} FCFA au challenge « {$challengeName} ».";
    }
}
