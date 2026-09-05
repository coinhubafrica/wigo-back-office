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

    /**
     * Taille de page réellement demandée, en deçà du plafond.
     *
     * Le plafond dit ce que Yango accepte, pas ce qu'il supporte : une passe
     * du parc qui réclame le maximum à chaque page se fait refuser en 429
     * avant d'avoir fini. Observé contre l'API vivante — le plafond reste la
     * borne, celle-ci est le régime de croisière.
     */
    public const DEFAULT_LIMIT = 500;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $parkId,
        protected int $limit = self::DEFAULT_LIMIT,
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
