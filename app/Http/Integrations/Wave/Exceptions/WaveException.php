<?php

namespace App\Http\Integrations\Wave\Exceptions;

use Exception;
use Saloon\Http\Response;
use Throwable;

/**
 * Échec d'un appel à l'API Wave.
 *
 * Levée par le connecteur, rattrapée par `SaloonWaveClient` : le contrat
 * `WaveClient` rend `null` plutôt que de lever, pour qu'un fournisseur muet
 * n'interrompe ni une recharge mobile ni l'affichage du back-office.
 */
class WaveException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?Response $response = null,
        public readonly ?Throwable $senderException = null,
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): ?int
    {
        return $this->response?->status();
    }

    /**
     * @return array<mixed>|string|null
     */
    public function getResponseBody(): array|string|null
    {
        return $this->response?->json() ?? $this->response?->body();
    }
}
