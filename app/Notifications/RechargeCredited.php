<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * « Votre recharge a été créditée. »
 *
 * Écrite en base d'abord : l'écran « Notifications » du mobile lit cette
 * table, le push FCM ne sera qu'un réveil. Ajouter ce canal se fera dans
 * `via()`, sans toucher au schéma ni aux appelants.
 */
class RechargeCredited extends Notification
{
    use Queueable;

    public function __construct(private Transaction $transaction) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
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
