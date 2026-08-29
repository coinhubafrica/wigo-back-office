<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mouvement d'argent, tel que l'application mobile le lit.
 *
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /**
             * @example "RCH-2026-0871"
             */
            'ref' => $this->reference,
            /**
             * @var 'recharge'|'order_payment'|'cnps_declaration'|'bonus_payout'
             */
            'type' => $this->type->value,
            /**
             * Le fil est plus étroit que le stockage : `to_review` — encaissé
             * par Wave mais pas encore porté au solde — se lit `failed`, le
             * conducteur n'ayant de toute façon rien reçu.
             */
            'status' => $this->status->wireStatus(),
            'status_label' => $this->status->label(),
            /**
             * Montant en FCFA.
             *
             * @example 10000
             */
            'amount' => $this->amount,
            /**
             * @example "XOF"
             */
            'currency' => $this->currency,
            'label' => $this->label,
            'sublabel' => $this->subtitle,
            /**
             * Adresse de paiement Wave. Nulle dès que la recharge n'est plus
             * en attente.
             */
            'wave_launch_url' => $this->status->awaitsCredit() ? $this->checkout_url : null,
            'receipt_code' => $this->receipt_code,
            'receipt_url' => $this->receipt_url,
            'initiated_at' => $this->initiated_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'credited_at' => $this->settled_at?->toIso8601String(),
        ];
    }
}
