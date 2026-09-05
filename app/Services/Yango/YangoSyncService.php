<?php

namespace App\Services\Yango;

use App\Contracts\YangoDirectory;
use App\Enums\DriverStatus;
use App\Http\Integrations\Yango\Requests\GetAllDriversRequest;
use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Rapproche le parc Yango de la base locale.
 *
 * Deux passes : les profils conducteurs (qui portent le véhicule affecté), puis
 * le parc complet (qui seul remonte les véhicules sans conducteur). La seconde
 * repasse sur des véhicules déjà vus par la première — l'upsert sur `yango_id`
 * rend l'opération idempotente.
 *
 * La passe de conducteurs renseigne aussi `yango_balance` : le bloc `accounts`
 * arrive dans la même page, autant le lire plutôt que de le jeter et de le
 * redemander conducteur par conducteur.
 *
 * Ce que la synchronisation ne fait jamais : réécrire le `status` d'un
 * conducteur (une suspension est une décision du back-office, Yango n'a pas à
 * la défaire), désactiver un véhicule absent, ou supprimer quoi que ce soit.
 * Ce que Yango ne remonte plus est signalé, pas effacé.
 */
class YangoSyncService
{
    public function __construct(
        private readonly YangoDirectory $directory,
    ) {}

    public function sync(int $pageSize = GetAllDriversRequest::MAX_LIMIT): YangoSyncResult
    {
        $result = new YangoSyncResult;

        // Repère posé avant la première écriture : tout ce qui garde un
        // `last_sync_at` antérieur n'a pas été remonté par cette passe.
        $startedAt = Carbon::now();

        foreach ($this->directory->drivers($pageSize) as $profile) {
            $this->syncDriver($profile, $result);
        }

        foreach ($this->directory->vehicles($pageSize) as $car) {
            if ($this->syncVehicle($car, null) !== null) {
                $result->vehiclesSynced++;
            }
        }

        $this->reportStale($startedAt, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function syncDriver(array $profile, YangoSyncResult $result): void
    {
        $yangoId = Arr::get($profile, 'driver_profile.id');

        if (! is_string($yangoId) || $yangoId === '') {
            $result->driversSkipped++;

            Log::warning('Yango : profil sans identifiant, ignoré');

            return;
        }

        $phone = $this->normalizePhone(Arr::get($profile, 'driver_profile.phones.0'));
        $driver = Driver::query()->where('yango_id', $yangoId)->first();

        if ($driver === null && $phone !== null) {
            // Adoption : la ligne existe déjà (créée à l'inscription mobile ou
            // par un agent) mais n'a jamais été rapprochée du parc.
            $driver = Driver::query()
                ->whereNull('yango_id')
                ->where('phone', $phone)
                ->first();

            if ($driver !== null) {
                $driver->yango_id = $yangoId;
                $result->driversAdopted++;
            }
        }

        if ($driver === null && $phone === null) {
            // Sans téléphone, impossible de créer : la colonne est requise et
            // unique, et c'est la seule clé d'entrée de l'application mobile.
            $result->driversSkipped++;

            Log::warning('Yango : conducteur sans téléphone exploitable, ignoré', [
                'yango_id' => $yangoId,
            ]);

            return;
        }

        if ($driver === null) {
            $driver = new Driver([
                'yango_id' => $yangoId,
                'phone' => $phone,
                // Connu de Yango, pas encore de l'application : aucune CGU
                // acceptée, donc « en attente ».
                'status' => DriverStatus::Dormant,
            ]);
        }

        $driver->fill([
            'first_name' => (string) Arr::get($profile, 'driver_profile.first_name', $driver->first_name ?? ''),
            'last_name' => (string) Arr::get($profile, 'driver_profile.last_name', $driver->last_name ?? ''),
            'license_number' => Arr::get($profile, 'driver_profile.driver_license.number', $driver->license_number),
            'last_sync_at' => Carbon::now(),
        ]);

        // La page de conducteurs porte déjà les comptes : le solde de tout le
        // parc s'écrit sans un appel de plus. Un solde absent n'est pas un
        // solde nul — on ne réécrit rien plutôt que d'effacer ce qu'on sait.
        $balance = YangoAccountBalance::read($profile);

        if ($balance !== null) {
            $driver->yango_balance = $balance;
            $driver->balance_read_at = Carbon::now();
            $result->driversBalanced++;
        }

        $driver->save();

        $result->driversSynced++;

        if ($this->syncVehicle(Arr::get($profile, 'car'), $driver) !== null) {
            $result->vehiclesSynced++;
        }
    }

    /**
     * Un véhicule tient sur une seule ligne : une réaffectation déplace
     * `driver_id`, elle n'ouvre pas de seconde ligne et ne garde pas
     * d'historique (`.ai/rules/models.md`).
     *
     * @param  array<string, mixed>|null  $car
     */
    private function syncVehicle(?array $car, ?Driver $driver): ?Vehicle
    {
        $yangoId = Arr::get($car ?? [], 'id');

        if (! is_string($yangoId) || $yangoId === '') {
            return null;
        }

        $vehicle = Vehicle::query()->firstOrNew(['yango_id' => $yangoId]);

        $vehicle->fill([
            'plate_number' => (string) Arr::get($car, 'number', $vehicle->plate_number ?? ''),
            'brand' => Arr::get($car, 'brand', $vehicle->brand),
            'model' => Arr::get($car, 'model', $vehicle->model),
            'color' => Arr::get($car, 'color', $vehicle->color),
            'last_sync_at' => Carbon::now(),
        ]);

        // La passe « parc » ne connaît aucune affectation : elle ne doit pas
        // détacher un véhicule que la passe « conducteurs » vient de rattacher.
        if ($driver !== null) {
            $vehicle->driver_id = $driver->getKey();
        }

        $vehicle->save();

        return $vehicle;
    }

    /**
     * Compte les lignes déjà rapprochées que Yango n'a pas remontées cette
     * fois-ci. On ne touche à rien : une absence peut venir d'une panne côté
     * Yango, pas forcément d'un départ du parc.
     */
    private function reportStale(Carbon $startedAt, YangoSyncResult $result): void
    {
        $result->staleDrivers = Driver::query()
            ->whereNotNull('yango_id')
            ->where(fn ($query) => $query
                ->whereNull('last_sync_at')
                ->orWhere('last_sync_at', '<', $startedAt))
            ->count();

        $result->staleVehicles = Vehicle::query()
            ->whereNotNull('yango_id')
            ->where(fn ($query) => $query
                ->whereNull('last_sync_at')
                ->orWhere('last_sync_at', '<', $startedAt))
            ->count();

        if ($result->staleDrivers > 0 || $result->staleVehicles > 0) {
            Log::warning('Yango : enregistrements non remontés', [
                'drivers' => $result->staleDrivers,
                'vehicles' => $result->staleVehicles,
            ]);
        }
    }

    /**
     * Ramène un numéro Yango à la forme E.164 stricte de `drivers.phone`
     * (`+2250700000000`), seule forme comparable à ce que l'application mobile
     * a enregistré. Rend `null` si rien d'exploitable n'en sort.
     */
    private function normalizePhone(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        // Un numéro ivoirien national (10 chiffres) arrive parfois sans son
        // indicatif : on le rétablit, sans quoi l'adoption ne rapprocherait
        // jamais la ligne créée côté mobile.
        if (! Str::startsWith($phone, '+') && strlen($digits) === 10) {
            $digits = '225'.$digits;
        }

        $normalized = '+'.$digits;

        return preg_match('/^\+[1-9]\d{7,14}$/', $normalized) === 1
            ? $normalized
            : null;
    }
}
