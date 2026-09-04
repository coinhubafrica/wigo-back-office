<?php

namespace App\Http\Integrations\Yango\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Porte un montant au solde d'un conducteur.
 *
 * Seule écriture d'argent de l'intégration. La référence de la transaction
 * sert de jeton d'idempotence : elle écrase celui, aléatoire, que pose le
 * connecteur, pour qu'un rejeu du même règlement ne crédite qu'une fois même
 * si Saloon réessaie ou si le job repasse.
 */
class CreateDriverTransactionRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $parkId,
        protected string $driverProfileId,
        protected int $amount,
        protected string $reference,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v2/parks/driver-profiles/transactions';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['X-Idempotency-Token' => $this->reference];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultBody(): array
    {
        return [
            'park_id' => $this->parkId,
            'driver_profile_id' => $this->driverProfileId,
            'amount' => (string) $this->amount,
            'currency' => 'XOF',
            'category_id' => 'partner_service_recurrent_payment',
            'description' => 'Recharge Wigo '.$this->reference,
        ];
    }
}
