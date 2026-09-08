<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesSyncPeriod;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\GetOrdersRequest;
use App\Jobs\SyncYangoDriverOrdersJob;
use App\Jobs\SyncYangoOrdersJob;
use App\Models\Driver;
use App\Services\Yango\YangoOrderSyncService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Rapatrie les courses Yango sur une période.
 *
 * Un job par journée : une passe d'un mois qui échoue au vingtième jour ne
 * refait pas les dix-neuf précédents, et deux journées peuvent se traiter de
 * front. Ce découpage ne contredit pas la règle de la passe parc — celle-ci
 * tient un repère `last_sync_at` qu'un second job fausserait, une journée de
 * courses ne tient rien de tel.
 *
 * Comme `yango:sync`, la commande met en file par défaut et n'exécute sur
 * place que sous `--now`, où elle affiche les compteurs.
 */
class SyncYangoOrdersCommand extends Command
{
    use ResolvesSyncPeriod;

    protected $signature = 'yango:sync-orders
        {--from= : Premier jour de la période (AAAA-MM-JJ, défaut : hier)}
        {--to= : Dernier jour de la période (AAAA-MM-JJ, défaut : aujourd\'hui)}
        {--limit=250 : Taille de page demandée à Yango (500 au plus)}
        {--driver= : Identifiant Yango d\'un conducteur, pour ne rapatrier que ses courses}
        {--now : Exécute la passe sur place au lieu de la mettre en file}';

    protected $description = 'Synchronise les courses depuis l\'API Yango Fleet sur une période';

    public function handle(YangoOrderSyncService $orders): int
    {
        $days = $this->resolvePeriod();

        if ($days === null) {
            return self::FAILURE;
        }

        $pageSize = min(
            GetOrdersRequest::MAX_LIMIT,
            max(1, (int) $this->option('limit')),
        );

        if ($this->option('driver') !== null) {
            return $this->syncOneDriver($orders, $days, $pageSize);
        }

        if (! $this->option('now')) {
            foreach ($days as $day) {
                SyncYangoOrdersJob::dispatch($day->toDateString(), $pageSize);
            }

            $this->components->info(sprintf(
                '%d journée(s) de courses mises en file.',
                count($days),
            ));

            return self::SUCCESS;
        }

        $synced = 0;
        $orphaned = 0;

        foreach ($days as $day) {
            try {
                $result = $orders->syncDay($day, $pageSize);
            } catch (YangoFleetException $exception) {
                $this->components->error(sprintf(
                    'Yango Fleet a refusé les courses du %s (%s) : %s',
                    $day->toDateString(),
                    $exception->getStatusCode() ?? 'sans statut',
                    $exception->getMessage(),
                ));

                return self::FAILURE;
            }

            $synced += $result->ordersSynced;
            $orphaned += $result->ordersOrphaned;
        }

        $this->components->info(sprintf('courses : %d sync sur %d journée(s)', $synced, count($days)));

        if ($orphaned > 0) {
            $this->components->warn(sprintf(
                'ignorées : %d courses de conducteurs inconnus (voir le journal)',
                $orphaned,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Passe restreinte à un conducteur, sur toute la période d'un coup.
     *
     * C'est aussi l'outil qui vérifie que Yango honore bien le filtre
     * `driver_profile.id` : la garde de `syncDriver()` interrompt la passe si
     * une course d'un autre conducteur revient, et le compteur le dit.
     *
     * @param  list<CarbonInterface>  $days
     */
    private function syncOneDriver(YangoOrderSyncService $orders, array $days, int $pageSize): int
    {
        $yangoId = (string) $this->option('driver');

        $driver = Driver::query()->where('yango_id', $yangoId)->first();

        if ($driver === null) {
            $this->components->error(sprintf('Aucun conducteur ne porte l\'identifiant Yango %s.', $yangoId));

            return self::FAILURE;
        }

        $from = $days[0]->copy()->startOfDay();
        $to = $days[count($days) - 1]->copy()->endOfDay();

        if (! $this->option('now')) {
            SyncYangoDriverOrdersJob::dispatch(
                (string) $driver->getKey(),
                $from->toDateTimeString(),
                $to->toDateTimeString(),
                $pageSize,
            );

            $this->components->info(sprintf('courses de %s mises en file.', $driver->fullName()));

            return self::SUCCESS;
        }

        try {
            $result = $orders->syncDriver($driver, $from, $to, $pageSize);
        } catch (YangoFleetException $exception) {
            $this->components->error(sprintf(
                'Yango Fleet a refusé les courses de %s (%s) : %s',
                $driver->fullName(),
                $exception->getStatusCode() ?? 'sans statut',
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'courses : %d sync pour %s',
            $result->ordersSynced,
            $driver->fullName(),
        ));

        if ($result->ordersOrphaned > 0) {
            $this->components->warn(sprintf(
                'ignorées : %d courses de conducteurs inconnus (voir le journal)',
                $result->ordersOrphaned,
            ));
        }

        return self::SUCCESS;
    }
}
