<?php

namespace App\Http\Resources;

use App\Models\PickupPoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Agence où retirer une commande de la boutique.
 *
 * @mixin PickupPoint
 */
class PickupPointResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            /**
             * Horaires d'ouverture en clair, `null` si l'agence n'en publie pas.
             *
             * @example "Lun–Sam 8 h – 18 h"
             */
            'opening_hours' => $this->opening_hours,
        ];
    }
}
