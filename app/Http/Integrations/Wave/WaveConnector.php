<?php

namespace App\Http\Integrations\Wave;

use App\Http\Integrations\Wave\Exceptions\WaveException;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;
use Saloon\Traits\Plugins\HasTimeout;
use Throwable;

/**
 * API Wave, pour un compte donné.
 *
 * La clé est passée au constructeur, jamais lue d'une constante : le
 * back-office opère **deux** comptes Wave — la boutique et la recharge Yango —
 * et le compte à débiter dépend de l'opération, pas de l'environnement. Une
 * instance = un compte ; c'est `SaloonWaveClient` qui choisit lequel.
 *
 * L'URL de base reste fixe : les deux comptes visent la même API, seules les
 * clés diffèrent.
 */
class WaveConnector extends Connector
{
    use AlwaysThrowOnErrors;
    use HasTimeout;

    protected int $connectTimeout = 15;

    protected int $requestTimeout = 30;

    public ?int $tries = 3;

    public ?int $retryInterval = 1000;

    public ?bool $useExponentialBackoff = true;

    public function __construct(protected string $apiKey) {}

    public function resolveBaseUrl(): string
    {
        return 'https://api.wave.com/v1';
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $pendingRequest->headers()->add('Accept', 'application/json');
        $pendingRequest->headers()->add('Authorization', 'Bearer '.$this->apiKey);
    }

    public function hasRequestFailed(Response $response): ?bool
    {
        return $response->status() >= 400;
    }

    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        return new WaveException(
            message: $response->json('message') ?? 'Erreur de l\'API Wave',
            response: $response,
            senderException: $senderException,
        );
    }
}
