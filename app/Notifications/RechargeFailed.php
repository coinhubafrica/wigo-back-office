<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Notifications\Concerns\BuildsFcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * « Votre recharge n'a pas abouti. »
 *
 * À distinguer de `RechargeNeedsReview` : ici rien n'a été prélevé, le
 * conducteur peut simplement réessayer. Le dire explicitement évite qu'il
 * croie avoir payé deux fois en relançant.
 */
class RechargeFailed extends Notification implements ShouldQueue
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
            'type' => 'recharge_failed',
            'category' => 'recharge',
            'title' => 'Recharge non aboutie',
            'body' => "Votre recharge de {$amount} FCFA n'a pas pu être effectuée. Aucun montant n'a été prélevé, vous pouvez réessayer.",
            'amount' => $this->transaction->amount,
            'reference' => $this->transaction->reference,
            'deeplink' => 'wigo://recharge',
        ];
    }
}
