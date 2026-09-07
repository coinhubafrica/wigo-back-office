<?php

namespace App\Services\Yango;

use Illuminate\Support\Arr;

/**
 * Ramène un profil `/v2/parks/contractors/driver-profile` à la forme d'une
 * ligne de `/v1/parks/driver-profiles/list`.
 *
 * L'alternative serait un second chemin d'écriture dans `YangoSyncService`,
 * qui lirait les mêmes champs à d'autres emplacements. C'est précisément ce
 * que la règle « un seul chemin » interdit : deux lectures du même conducteur
 * divergeraient au premier champ ajouté. La traduction vit donc ici, en un
 * seul endroit, et `syncDriver()` ne sait pas de quel endpoint sa ligne vient.
 *
 * L'identifiant ne figure pas dans la réponse : c'est celui qu'on a demandé,
 * il est donc réinjecté. Sans lui, `syncDriver()` écarterait la ligne pour
 * « profil sans identifiant ».
 *
 * Le solde n'est pas traduit : `account` de la v2 porte `balance_limit`, le
 * plafond de découvert, et non `balance`. Faire passer l'un pour l'autre
 * afficherait un solde faux — `YangoAccountBalance::read()` ne trouvera donc
 * pas de bloc `accounts`, rendra `null`, et la passe ne réécrira rien.
 */
final class YangoProfileShape
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function fromContractorProfile(array $profile, string $yangoId): array
    {
        return [
            'driver_profile' => array_filter([
                'id' => $yangoId,
                'first_name' => Arr::get($profile, 'person.full_name.first_name'),
                'last_name' => Arr::get($profile, 'person.full_name.last_name'),
                'phones' => self::phones($profile),
                'driver_license' => array_filter([
                    'number' => Arr::get($profile, 'person.driver_license.number'),
                ], fn (mixed $value): bool => $value !== null),
            ], fn (mixed $value): bool => $value !== null && $value !== []),
        ];
    }

    /**
     * Véhicule affecté, tel que la v2 le nomme : un identifiant seul, jamais
     * la fiche. Rend `null` quand le conducteur n'a pas de voiture.
     *
     * @param  array<string, mixed>  $profile
     */
    public static function carId(array $profile): ?string
    {
        $carId = Arr::get($profile, 'car_id');

        return is_string($carId) && $carId !== '' ? $carId : null;
    }

    /**
     * La v1 rend une liste de téléphones, la v2 un seul. On rend la liste
     * attendue par `syncDriver()`, ou `null` s'il n'y a rien à normaliser —
     * un profil sans téléphone exploitable est écarté plus loin, et c'est le
     * comportement voulu (`drivers.phone` est requis et unique).
     *
     * @param  array<string, mixed>  $profile
     * @return list<string>|null
     */
    private static function phones(array $profile): ?array
    {
        $phone = Arr::get($profile, 'person.contact_info.phone');

        return is_string($phone) && $phone !== '' ? [$phone] : null;
    }
}
