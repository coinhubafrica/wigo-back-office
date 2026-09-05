<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesSyncPeriod;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\GetTransactionsRequest;
use App\Jobs\SyncYangoTransactionsJob;
use App\Services\Yango\YangoTransactionSyncService;
use Illuminate\Console\Command;

/**
 * Copie le grand livre du parc Yango sur une période.
 *
 * Mêmes options et même découpage par journée que `yango:sync-orders`. Lecture
 * seule : cette commande ne crédite rien, elle rapatrie ce que Yango a
 * comptabilisé pour que le back-office puisse le rapprocher de ses propres
 * `transactions` — l'argent local, encaissé par Wave.
 */
class SyncYangoTransactionsCommand extends Command
{
    use ResolvesSyncPeriod;

    protected $signature = 'yango:sync-transactions
        {--from= : Premier jour de la période (AAAA-MM-JJ, défaut : hier)}
        {--to= : Dernier jour de la période (AAAA-MM-JJ, défaut : aujourd\'hui)}
        {--limit=500 : Taille de page demandée à Yango (1000 au plus)}
        {--now : Exécute la passe sur place au lieu de la mettre en file}';

    protected $description = 'Synchronise les transactions depuis l\'API Yango Fleet sur une période';

    public function handle(YangoTransactionSyncService $transactions): int
    {
        $days = $this->resolvePeriod();

        if ($days === null) {
            return self::FAILURE;
        }

        $pageSize = min(
            GetTransactionsRequest::MAX_LIMIT,
            max(1, (int) $this->option('limit')),
        );

        if (! $this->option('now')) {
            foreach ($days as $day) {
                SyncYangoTransactionsJob::dispatch($day->toDateString(), $pageSize);
            }

            $this->components->info(sprintf(
                '%d journée(s) de transactions mises en file.',
                count($days),
            ));

            return self::SUCCESS;
        }

        $synced = 0;
        $unattached = 0;

        foreach ($days as $day) {
            try {
                $result = $transactions->syncDay($day, $pageSize);
            } catch (YangoFleetException $exception) {
                $this->components->error(sprintf(
                    'Yango Fleet a refusé les transactions du %s (%s) : %s',
                    $day->toDateString(),
                    $exception->getStatusCode() ?? 'sans statut',
                    $exception->getMessage(),
                ));

                return self::FAILURE;
            }

            $synced += $result->transactionsSynced;
            $unattached += $result->transactionsUnattached;
        }

        $this->components->info(sprintf(
            'transactions : %d sync sur %d journée(s)',
            $synced,
            count($days),
        ));

        if ($unattached > 0) {
            $this->components->warn(sprintf(
                'sans conducteur rapproché : %d mouvements',
                $unattached,
            ));
        }

        return self::SUCCESS;
    }
}
