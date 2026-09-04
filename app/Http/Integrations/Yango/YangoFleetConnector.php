<?php

namespace App\Http\Integrations\Yango;

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use Illuminate\Support\Str;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;
use Saloon\Traits\Plugins\HasTimeout;
use Throwable;

/**
 * API Yango Fleet : c'est elle qui fait foi sur le parc.
 *
 * Les identifiants sont passés au constructeur — ils viennent des réglages
 * (`FleetSettings`), pas d'une constante : le back-office n'opère qu'un seul
 * parc, mais ses clés se corrigent à l'écran. L'URL de base en fait partie,
 * pour pouvoir viser un bac à sable sans toucher au code.
 *
 * `X-Idempotency-Token` est régénéré à chaque requête : sans effet sur les
 * lectures, il protège les écritures d'un doublon si Saloon rejoue l'appel.
 */
class YangoFleetConnector extends Connector
{
    use AlwaysThrowOnErrors;
    use HasTimeout;

    protected int $connectTimeout = 30;

    protected int $requestTimeout = 60;

    public ?int $tries = 3;

    public ?int $retryInterval = 1000;

    public ?bool $useExponentialBackoff = true;

    public function __construct(
        protected string $baseUrl,
        protected string $parkId,
        protected string $apiKey,
    ) {}

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $pendingRequest->headers()->add('Accept-Language', 'en');
        $pendingRequest->headers()->add('Accept', 'application/json');
        $pendingRequest->headers()->add('Content-Type', 'application/json');
        $pendingRequest->headers()->add('X-API-Key', $this->apiKey);
        $pendingRequest->headers()->add('X-Park-Id', $this->parkId);
        $pendingRequest->headers()->add('X-Client-ID', 'taxi/park/'.$this->parkId);
        $pendingRequest->headers()->add('X-Idempotency-Token', Str::uuid()->toString());
    }

    public function hasRequestFailed(Response $response): ?bool
    {
        return $response->status() >= 400;
    }

    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        return new YangoFleetException(
            message: $response->json('message') ?? 'Erreur de l\'API Yango Fleet',
            response: $response,
            senderException: $senderException,
        );
    }
}
