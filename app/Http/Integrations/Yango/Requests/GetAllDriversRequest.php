<?php

namespace App\Http\Integrations\Yango\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Page de profils conducteurs du parc.
 *
 * La liste `fields` décide de ce que Yango renvoie : le véhicule affecté
 * (`car`) y figure, si bien qu'une seule passe alimente conducteurs et
 * véhicules. Pagination par décalage — Yango n'offre pas de curseur ici.
 */
class GetAllDriversRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $parkId,
        protected int $limit = 100,
        protected int $offset = 0,
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
                ],
            ],
            'fields' => [
                'driver_profile' => [
                    'id',
                    'first_name',
                    'last_name',
                    'created_date',
                    'work_status',
                    'work_rule_id',
                    'driver_license',
                    'phones',
                ],
                'account' => [
                    'id',
                    'type',
                    'balance',
                    'currency',
                ],
                'current_status' => [
                    'status',
                    'status_updated_at',
                ],
                'car' => [
                    'id',
                    'status',
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
            'limit' => $this->limit,
            'offset' => $this->offset,
            'sort_order' => [
                [
                    'direction' => 'desc',
                    'field' => 'driver_profile.created_date',
                ],
            ],
        ];
    }
}
