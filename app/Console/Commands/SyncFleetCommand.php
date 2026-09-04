<?php

namespace App\Console\Commands;

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Services\Fleet\FleetSyncService;
use Illuminate\Console\Command;

/**
 * Rapproche le parc Yango de la base locale.
 *
 * Rendue en échec quand l'API refuse : le planificateur doit voir passer une
 * clé invalide plutôt que de répéter une passe vide toutes les heures.
 */
class SyncFleetCommand extends Command
{
    protected $signature = 'fleet:sync {--limit=100 : Taille de page demandée à Yango}';

    protected $description = 'Synchronise les conducteurs et véhicules depuis l\'API Yango Fleet';

    public function handle(FleetSyncService $sync): int
    {
        $pageSize = max(1, (int) $this->option('limit'));

        try {
            $result = $sync->sync($pageSize);
        } catch (YangoFleetException $exception) {
            $this->components->error(sprintf(
                'Yango Fleet a refusé la synchronisation (%s) : %s',
                $exception->getStatusCode() ?? 'sans statut',
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'conducteurs : %d sync, %d adoptés, %d ignorés',
            $result->driversSynced,
            $result->driversAdopted,
            $result->driversSkipped,
        ));

        $this->components->info(sprintf('véhicules : %d sync', $result->vehiclesSynced));

        if ($result->staleDrivers > 0 || $result->staleVehicles > 0) {
            $this->components->warn(sprintf(
                'non remontés : %d conducteurs, %d véhicules (voir le journal)',
                $result->staleDrivers,
                $result->staleVehicles,
            ));
        }

        return self::SUCCESS;
    }
}
