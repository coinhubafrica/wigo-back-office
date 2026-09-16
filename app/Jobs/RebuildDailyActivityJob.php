<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksYangoSyncRun;
use App\Services\Challenges\DailyActivityRebuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Recompte le cumul journalier d'une journée à partir des courses déjà en base.
 *
 * Distinct de `SyncYangoOrdersJob`, et c'est le point : celui-ci ne parle pas à
 * Yango. Une journée dont les courses sont déjà rapatriées mais dont le cumul
 * manque — l'état qu'une passe interrompue laissait derrière elle — se répare
 * donc sans quota, sans 429 et en quelques secondes, là où redemander la
 * journée à Yango coûte une boucle de curseur entière.
 *
 * `ShouldBeUnique` par journée, comme les passes datées : deux reconstructions
 * simultanées de la même journée se disputeraient les mêmes lignes.
 *
 * `repairTotals` est porté par le job plutôt que déduit : rattraper une période
 * met une journée par job en file, et rechaîner le cumul de carrière depuis
 * chacune referait le même travail autant de fois qu'il y a de journées. Le
 * composant ne le demande donc que sur la plus ancienne (cf.
 * `Livewire\YangoSync\Index::queue()`), et la chaîne se répare d'un coup
 * jusqu'à aujourd'hui.
 */
class RebuildDailyActivityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable, TracksYangoSyncRun;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    /**
     * Une agrégation et des écritures par lots : sans commune mesure avec une
     * passe Yango, mais le rechaînage du cumul traverse tout le parc sur toutes
     * les journées postérieures, donc large.
     */
    public int $timeout = 900;

    public function __construct(
        public string $day,
        public bool $repairTotals = true,
    ) {}

    public function uniqueId(): string
    {
        return 'daily-activity:'.$this->day;
    }

    public function handle(DailyActivityRebuilder $rebuilder): void
    {
        $day = Carbon::parse($this->day);

        $this->markRunning();

        $touched = $rebuilder->rebuildDay($day);
        $repaired = $this->repairTotals ? $rebuilder->repairTotalsFrom($day) : 0;

        $this->markFinished([
            'drivers_touched' => $touched,
            'totals_repaired' => $repaired,
        ]);

        Log::info('Activité journalière reconstruite', [
            'day' => $day->toDateString(),
            'drivers_touched' => $touched,
            'totals_repaired' => $repaired,
        ]);
    }
}
