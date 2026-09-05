<?php

namespace App\Http\Integrations\Yango\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Page de véhicules du parc, affectés ou non.
 *
 * La passe conducteurs ne remonte que les véhicules attribués : celle-ci est
 * la seule à voir le parc dormant.
 *
 * Pagination par décalage, avec un `total` dans la réponse.
 */
class GetAllVehiclesRequest extends Request implements HasBody
{
    use HasJsonBody;

    /** Plafond imposé par Yango sur cet endpoint. */
    public const MAX_LIMIT = 1000;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $parkId,
        protected int $limit = self::MAX_LIMIT,
        protected int $offset = 0,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v1/parks/cars/list';
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
                ],
            ],
            'fields' => [
                'car' => [
                    'id',
                    'status',
                    'amenities',
                    'category',
                    'callsign',
                    'brand',
                    'model',
                    'year',
                    'color',
                    'number',
                    'registration_cert',
                    'vin',
                ],
            ],
            'limit' => min($this->limit, self::MAX_LIMIT),
            'offset' => $this->offset,
        ];
    }
}
