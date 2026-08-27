<?php

namespace App\Http\Resources;

use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma `Driver` du contrat mobile. Les noms exposés suivent openapi.yaml
 * (`license_no`), pas les colonnes.
 *
 * @mixin Driver
 */
class DriverResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            /**
             * Numéro au format international.
             *
             * @example +2250717738299
             */
            'phone' => $this->phone,
            /**
             * Numéro de permis de conduire.
             *
             * @example COMB012500370370A
             */
            'license_no' => $this->license_number,
            'photo_url' => $this->photo_url,
            /**
             * Un conducteur `suspended` conserve son jeton mais reçoit 403 sur
             * les ressources métier.
             *
             * @var 'active'|'suspended'|'dormant'
             */
            'status' => $this->status->value,
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle === null ? null : [
                'make' => $this->vehicle->brand,
                'model' => $this->vehicle->model,
                'color' => $this->vehicle->color,
                'plate' => $this->vehicle->plate_number,
            ]),
        ];
    }
}
