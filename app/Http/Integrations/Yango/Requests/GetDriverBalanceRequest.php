<?php

namespace App\Http\Integrations\Yango\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Solde du compte d'un conducteur.
 *
 * Yango n'expose pas de lecture unitaire : on demande la liste filtrée sur un
 * seul profil. Le solde vit sous `accounts`, pas sous `driver_profile`.
 */
class GetDriverBalanceRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $parkId,
        protected string $driverProfileId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v1/parks/driver-profiles/list';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultBody(): array
    {
        return [
            'query' => [
                'park' => [
                    'id' => $this->parkId,
                    'driver_profile' => [
                        'id' => [$this->driverProfileId],
                    ],
                ],
            ],
            'fields' => [
                'driver_profile' => ['id'],
                'account' => ['id', 'type', 'balance', 'currency'],
            ],
            'limit' => 1,
        ];
    }
}
