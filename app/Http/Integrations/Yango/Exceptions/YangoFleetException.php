<?php

namespace App\Http\Integrations\Yango\Exceptions;

use Exception;
use Saloon\Http\Response;
use Throwable;

/**
 * Échec d'un appel à l'API Yango Fleet.
 *
 * Contrairement à `YangoClient`, qui rend `null` quand le fournisseur est muet,
 * l'annuaire lève : une passe de synchronisation interrompue au milieu ne doit
 * jamais écrire un parc tronqué, elle doit remonter pour que le job réessaie.
 *
 * `getStatusCode()` porte la décision du job : 401/403 ne se répare pas en
 * réessayant, tout le reste oui.
 */
class YangoFleetException extends Exception
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
