<?php

namespace App\Console\Commands;

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Jobs\SyncYangoJob;
use App\Services\Yango\YangoSyncService;
use Illuminate\Console\Command;

/**
 * Rapproche le parc Yango de la base locale.
 *
 * Par défaut la passe part en file : elle espace désormais ses appels pour ne
 * plus se faire refuser par Yango, ce qui l'allonge, et le planificateur n'a
 * pas à rester bloqué le temps qu'elle finisse. `--now` la lance sur place —
 * c'est le chemin de l'agent au terminal, qui veut voir les compteurs.
 *
 * Le chemin planifié perd donc la sortie console et le code d'échec. C'est
 * assumé : le planificateur jetait déjà cette sortie. `SyncYangoJob` journalise
 * les compteurs, et une clé refusée atterrit dans `failed_jobs`, plus durable
 * qu'un code de sortie que personne ne lit.
 */
class SyncYangoCommand extends Command
{
    protected $signature = 'yango:sync
        {--limit=100 : Taille de page demandée à Yango}
        {--now : Exécute la passe sur place au lieu de la mettre en file}';

    protected $description = 'Synchronise les conducteurs et véhicules depuis l\'API Yango Fleet';

    public function handle(YangoSyncService $sync): int
    {
        $pageSize = max(1, (int) $this->option('limit'));

        if (! $this->option('now')) {
            SyncYangoJob::dispatch($pageSize);

            $this->components->info('Passe de synchronisation mise en file.');

            return self::SUCCESS;
        }

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
