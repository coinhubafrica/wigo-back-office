<?php

namespace App\Console\Commands;

use App\Models\YangoDailyStat;
use App\Models\YangoOrder;
use App\Services\Yango\YangoDailyStatsRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Peuple `yango_daily_stats` pour l'historique déjà en base.
 *
 * Journée par journée, et c'est le point : la journée **est** le découpage.
 * Une agrégation de tout l'historique en une requête serait exactement celle
 * qui dépasse le délai (cf. le docbloc de la migration). Comptée séparément,
 * chaque journée coûte environ 127 ms — les quelque quatre-vingt-dix journées
 * du parc tiennent donc en une douzaine de secondes.
 *
 * Distincte de `RebuildDailyActivityJob`, qui rechaîne en plus `orders_total`
 * sur tout le parc pour chaque journée : l'appeler quatre-vingt-dix fois
 * referait quatre-vingt-dix fois ce parcours. Le cumul par journée n'a aucune
 * colonne chaînée et n'a besoin de rien de tel.
 */
class BackfillYangoDailyStatsCommand extends Command
{
    protected $signature = 'yango:backfill-daily-stats
        {--from= : Première journée (AAAA-MM-JJ). Défaut : la plus ancienne course en base.}
        {--to= : Dernière journée (AAAA-MM-JJ). Défaut : aujourd\'hui.}
        {--force : Recompte aussi les journées déjà comptées.}';

    protected $description = 'Recompte les cumuls de courses par journée depuis yango_orders.';

    public function handle(YangoDailyStatsRecorder $recorder): int
    {
        $from = $this->resolveFrom();

        if ($from === null) {
            $this->components->warn('Aucune course en base : rien à compter.');

            return self::SUCCESS;
        }

        $to = $this->option('to') !== null
            ? Carbon::parse((string) $this->option('to'))->startOfDay()
            : Carbon::today();

        if ($to->lessThan($from)) {
            $this->components->error('La période se termine avant de commencer.');

            return self::FAILURE;
        }

        // Les journées déjà comptées en une seule lecture : la table est
        // petite, et la relire par journée serait un aller-retour de plus par
        // tour de boucle.
        $alreadyCounted = YangoDailyStat::query()
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->pluck('day')
            ->map(fn ($day): string => Carbon::parse((string) $day)->toDateString())
            ->flip();

        $force = (bool) $this->option('force');
        $counted = 0;
        $skipped = 0;
        $failed = 0;

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $date = $day->toDateString();

            if (! $force && $alreadyCounted->has($date)) {
                $skipped++;

                continue;
            }

            try {
                $recorder->recordDay($day->copy());
                $counted++;
            } catch (Throwable $exception) {
                // Une journée qui échoue ne doit pas emporter les
                // quatre-vingt-neuf autres.
                $failed++;

                Log::error('Yango : cumul de journée non écrit au rattrapage', [
                    'day' => $date,
                    'exception' => $exception->getMessage(),
                ]);

                $this->components->warn(sprintf('%s : %s', $date, $exception->getMessage()));
            }
        }

        $this->components->info(sprintf(
            '%d journée(s) comptée(s), %d ignorée(s), %d en échec.',
            $counted,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Première journée à compter : celle demandée, sinon la plus ancienne
     * course en base.
     *
     * `min()` sur une colonne indexée est une lecture d'extrémité d'index, pas
     * un parcours.
     */
    private function resolveFrom(): ?Carbon
    {
        if ($this->option('from') !== null) {
            return Carbon::parse((string) $this->option('from'))->startOfDay();
        }

        $oldest = YangoOrder::query()->min('completed_at');

        return $oldest === null ? null : Carbon::parse((string) $oldest)->startOfDay();
    }
}
