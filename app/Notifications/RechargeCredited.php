<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Notifications\Concerns\BuildsFcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * « Votre recharge a été créditée. »
 *
 * Écrite en base d'abord : l'écran « Notifications » du mobile lit cette
 * table, le push FCM n'est qu'un réveil.
 *
 * `ShouldQueue` comme les autres notifications poussées : le canal FCM sort
 * sur le réseau, et `CreditRechargeJob` ne doit pas porter cette latence — ni
 * l'échec qui va avec.
 */
class RechargeCredited extends Notification implements ShouldQueue
{
    use BuildsFcmMessage, Queueable;

    public function __construct(private Transaction $transaction) {}

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
        $amount = number_format($this->transaction->amount, 0, ',', ' ');

        return [
            'type' => 'recharge_credited',
            'category' => 'recharge',
            'title' => 'Recharge créditée',
            'body' => "Votre solde YANGO PRO a été crédité de {$amount} FCFA.",
            'amount' => $this->transaction->amount,
            'reference' => $this->transaction->reference,
            'deeplink' => 'wigo://recharge',
        ];
    }
}
