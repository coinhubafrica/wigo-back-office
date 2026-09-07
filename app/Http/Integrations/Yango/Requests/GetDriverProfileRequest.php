<?php

namespace App\Http\Integrations\Yango\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Un profil conducteur, demandé nommément.
 *
 * Complément indispensable de `GetAllDriversRequest` : la passe parc n'atteint
 * pas la fin d'un grand parc avant que Yango la coupe, si bien qu'une course
 * ou une transaction peut nommer un conducteur que la liste n'a pas encore
 * remonté. Cet appel-ci le rapatrie à la demande, sans attendre que le tour
 * de parc arrive jusqu'à lui.
 *
 * Deux différences de forme avec les endpoints de liste, à ne pas confondre :
 *
 * - **GET avec paramètre d'URL**, là où le parc est en POST avec un corps
 *   JSON. C'est une autre génération de l'API (`/v2/parks/contractors/…`).
 * - **La réponse n'a pas la forme d'une ligne de liste** : les noms vivent
 *   sous `person.full_name`, le téléphone sous `person.contact_info.phone`, et
 *   l'identifiant du profil n'y figure pas du tout — c'est celui qu'on a
 *   passé en paramètre. `YangoProfileShape` fait la traduction.
 */
class GetDriverProfileRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $contractorProfileId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v2/parks/contractors/driver-profile';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultQuery(): array
    {
        return [
            'contractor_profile_id' => $this->contractorProfileId,
        ];
    }
}
