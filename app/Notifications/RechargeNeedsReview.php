<?php

namespace App\Notifications;

use App\Models\Transaction;
use App\Notifications\Concerns\BuildsFcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * « Votre recharge n'a pas été créditée. »
 *
 * Le cas qui coûte de l'argent : Wave a encaissé, le crédit du solde Yango a
 * échoué, un agent doit rejouer l'opération. Sans cette ligne, le conducteur
 * a payé et ne l'apprend qu'en ouvrant son portefeuille.
 *
 * Le message reste au niveau du conducteur : il a payé, son solde n'a pas
 * bougé, le support reprend la main. La nuance `to_review` sert au
 * back-office, pas au téléphone (cf. `TransactionStatus::wireStatus()`) — on
 * ne lui parle donc ni de Yango ni de Wave.
 */
class RechargeNeedsReview extends Notification implements ShouldQueue
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
            'type' => 'recharge_needs_review',
            'category' => 'recharge',
            'title' => 'Recharge en cours de vérification',
            'body' => "Votre paiement de {$amount} FCFA a bien été reçu, mais votre solde n'a pas encore été crédité. Nos équipes régularisent votre recharge.",
            'amount' => $this->transaction->amount,
            'reference' => $this->transaction->reference,
            'deeplink' => 'wigo://recharge',
        ];
    }
}
