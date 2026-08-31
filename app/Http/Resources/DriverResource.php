<?php

namespace App\Http\Resources;

use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

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
     * Validité de l'URL signée de la photo. Assez longue pour qu'un écran de
     * profil déjà ouvert continue de l'afficher, assez courte pour qu'une URL
     * recopiée ailleurs cesse vite de fonctionner.
     */
    private const PHOTO_URL_TTL_MINUTES = 60;

    /**
     * URL signée et temporaire de la photo de profil, `null` tant qu'aucune
     * photo n'a été déposée. La colonne stocke un chemin sur le disque privé,
     * jamais une URL : elle n'est pas exposée telle quelle.
     */
    public static function photoUrl(Driver $driver): ?string
    {
        if ($driver->photo_url === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'api.v1.photo',
            now()->addMinutes(self::PHOTO_URL_TTL_MINUTES),
            ['driver' => $driver->id],
        );
    }

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
            /**
             * URL signée et temporaire (une heure). Rafraîchie à chaque
             * lecture du profil.
             */
            'photo_url' => self::photoUrl($this->resource),
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
