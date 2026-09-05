<?php

namespace App\Services\Yango;

use App\Contracts\YangoDirectory;
use App\Enums\DriverStatus;
use App\Http\Integrations\Yango\Requests\GetAllDriversRequest;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Settings\YangoSettings;
use Carbon\Exceptions\InvalidFormatException;
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

    public function sync(int $pageSize = GetAllDriversRequest::DEFAULT_LIMIT): YangoSyncResult
    {
        $result = new YangoSyncResult;
        $settings = app(YangoSettings::class);

        $drivers = new YangoSyncCursor($settings->drivers_offset);
        $vehicles = new YangoSyncCursor($settings->vehicles_offset);

        // Repère posé au début du **tour**, pas de la passe : un tour s'étale
        // sur plusieurs passes et plusieurs heures, et le mesurer depuis cette
        // passe-ci compterait « non remontées » toutes les lignes que les
        // passes précédentes du même tour ont pourtant rapprochées.
        $lapStartedAt = $this->lapMarker($settings, $drivers);

        // La progression est enregistrée quoi qu'il arrive : une passe coupée
        // par un 429 au milieu du parc doit laisser derrière elle de quoi
        // reprendre, sinon la suivante repasse sur les mêmes premières pages
        // et n'atteint jamais les dernières.
        try {
            foreach ($this->directory->drivers($pageSize, $drivers) as $profile) {
                $this->syncDriver($profile, $result);
            }

            foreach ($this->directory->vehicles($pageSize, $vehicles) as $car) {
                if ($this->syncVehicle($car, null) !== null) {
                    $result->vehiclesSynced++;
                }
            }
        } finally {
            $this->rememberProgress($settings, $drivers, $vehicles);
        }

        $result->driversOffset = $drivers->offset;
        $result->completedLap = $drivers->completed && $vehicles->completed;

        // Un tour partiel ne peut rien dire des lignes qu'il n'a pas vues :
        // les compter « non remontées » accuserait Yango de ne plus connaître
        // un conducteur que la passe n'a simplement pas encore atteint.
        if ($result->completedLap) {
            $this->reportStale($lapStartedAt, $result);
        }

        return $result;
    }

    /**
     * Début du tour en cours : posé maintenant si le tour commence, relu
     * sinon. Une valeur illisible repart de maintenant plutôt que de faire
     * tomber la passe pour un réglage abîmé.
     */
    private function lapMarker(YangoSettings $settings, YangoSyncCursor $drivers): Carbon
    {
        if ($drivers->offset === 0 || blank($settings->lap_started_at)) {
            $startedAt = Carbon::now();

            $settings->lap_started_at = $startedAt->toIso8601String();
            $settings->save();

            return $startedAt;
        }

        try {
            return Carbon::parse($settings->lap_started_at);
        } catch (InvalidFormatException) {
            return Carbon::now();
        }
    }

    /**
     * Note où la passe s'est arrêtée, pour que la suivante reprenne là.
     *
     * Le parc des véhicules ne s'entame qu'une fois les conducteurs bouclés :
     * tant que ceux-ci ne sont pas finis, le repère véhicules ne doit pas
     * bouger, sans quoi une reprise sauterait des voitures.
     */
    private function rememberProgress(
        YangoSettings $settings,
        YangoSyncCursor $drivers,
        YangoSyncCursor $vehicles,
    ): void {
        $settings->drivers_offset = $drivers->nextOffset();
        $settings->vehicles_offset = $drivers->completed ? $vehicles->nextOffset() : $settings->vehicles_offset;

        // Tour bouclé : le repère s'efface, la passe suivante en ouvrira un
        // neuf. Sans cet effacement, tous les tours à venir se compareraient à
        // la date du premier.
        if ($drivers->completed && $vehicles->completed) {
            $settings->lap_started_at = '';
        }

        $settings->save();
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
