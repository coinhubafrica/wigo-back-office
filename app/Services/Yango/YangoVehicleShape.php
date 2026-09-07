<?php

namespace App\Services\Yango;

use Illuminate\Support\Arr;

/**
 * Ramène une fiche `/v2/parks/vehicles/car` à la forme d'une ligne de
 * `/v1/parks/cars/list`.
 *
 * Même raison qu'à côté (`YangoProfileShape`) : `syncVehicle()` reste le seul
 * chemin d'écriture d'un véhicule, et ne sait pas de quel endpoint sa ligne
 * vient.
 *
 * Deux pièges de nommage, vérifiés contre la documentation des deux endpoints :
 *
 * - la plaque s'appelle `number` côté liste et
 *   `vehicle_licenses.licence_plate_number` côté fiche — orthographe
 *   britannique, à ne pas corriger en `license` ;
 * - `status` est à la racine côté liste et sous `park_profile` côté fiche.
 *
 * L'identifiant, comme pour le conducteur, ne figure pas dans la réponse : on
 * réinjecte celui qu'on a demandé, sans quoi `syncVehicle()` rendrait `null`
 * faute d'`id`.
 */
final class YangoVehicleShape
{
    /**
     * @param  array<string, mixed>  $car
     * @return array<string, mixed>
     */
    public static function fromCar(array $car, string $yangoId): array
    {
        return array_filter([
            'id' => $yangoId,
            'number' => Arr::get($car, 'vehicle_licenses.licence_plate_number'),
            'brand' => Arr::get($car, 'vehicle_specifications.brand'),
            'model' => Arr::get($car, 'vehicle_specifications.model'),
            'color' => Arr::get($car, 'vehicle_specifications.color'),
        ], fn (mixed $value): bool => $value !== null);
    }
}
