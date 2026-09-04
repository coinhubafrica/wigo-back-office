<?php

namespace App\Http\Integrations\Wave\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Ouvre une session de paiement Wave Checkout.
 *
 * `client_reference` porte la référence de la transaction : c'est par elle que
 * le webhook retrouvera la ligne au retour. Elle sert aussi de clé
 * d'idempotence, pour qu'un renvoi n'ouvre pas deux sessions payables.
 */
class CreateCheckoutSessionRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected int $amount,
        protected string $currency,
        protected string $reference,
        protected string $successUrl,
        protected string $errorUrl,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/checkout/sessions';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['idempotency-key' => $this->reference];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultBody(): array
    {
        return [
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'client_reference' => $this->reference,
            'success_url' => $this->successUrl,
            'error_url' => $this->errorUrl,
        ];
    }
}
