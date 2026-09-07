<?php

namespace App\Http\Integrations\Yango\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Un véhicule, demandé nommément.
 *
 * Même raison d'être que `GetDriverProfileRequest` : la passe parc est coupée
 * avant la fin, et un conducteur rapatrié à la demande porte un `car_id` que
 * la liste des véhicules n'a pas encore atteint.
 *
 * GET avec paramètre d'URL, et une réponse qui ne ressemble pas à une ligne de
 * `/v1/parks/cars/list` : marque, modèle et couleur vivent sous
 * `vehicle_specifications`, la plaque sous
 * `vehicle_licenses.licence_plate_number` — orthographe britannique, et
 * `number` côté liste. `YangoVehicleShape` fait la traduction.
 */
class GetVehicleRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $vehicleId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v2/parks/vehicles/car';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultQuery(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
        ];
    }
}
