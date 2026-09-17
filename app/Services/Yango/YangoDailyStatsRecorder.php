<?php

namespace App\Services\Yango;

use App\Enums\YangoOrderStatus;
use App\Models\YangoDailyStat;
use App\Models\YangoOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Compte les courses d'une journée par statut, et range le résultat.
 *
 * L'écriture est **absolue, jamais incrémentale** : chaque passage redérive la
 * journée entière depuis `yango_orders`. Resynchroniser deux fois, ou
 * resynchroniser après que Yango a retiré une course, converge donc sur le bon
 * nombre — il n'existe aucun chemin par lequel un compte puisse dériver.
 */
class YangoDailyStatsRecorder
{
    /**
     * Recompte la journée et écrit sa ligne de cumul.
     */
    public function recordDay(CarbonInterface $day): YangoDailyStat
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        /*
        | Un compte par statut, et surtout **pas** un `GROUP BY status`.
        |
        | C'est contre-intuitif, alors voici les mesures, prises sur la base
        | réelle (2 353 644 courses) pour une seule journée :
        |
        |   GROUP BY status + BETWEEN ...................... 8 409 ms
        |   where(status,'complete') + BETWEEN + count() ....  100 ms
        |   where(status,'cancelled') + BETWEEN + count() ...   65 ms
        |   les trois statuts comptés séparément ............  127 ms
        |
        | L'index utile est `(status, completed_at, driver_id)` : MySQL exige
        | l'égalité sur `status` avant de pouvoir se servir de `completed_at`
        | comme borne. Un `GROUP BY` ne peut pas épingler `status`, le plan
        | retombe donc sur un parcours complet de l'index. Trois requêtes bon
        | marché battent la requête « astucieuse » d'un facteur soixante-six.
        |
        | Même loi que `.ai/rules/challenges-livewire-challenges.md` : ni
        | `whereDate()`, ni `DATE(colonne)`, et les bornes en constantes.
        | Un test le verrouille — ne pas « optimiser » en sens inverse.
        */
        $counts = [];

        foreach (YangoOrderStatus::cases() as $status) {
            $counts[$status->value] = $this->countStatus($status, $from, $to);
        }

        return YangoDailyStat::query()->updateOrCreate(
            // La clé doit être la chaîne « Y-m-d » et non un objet daté : la
            // colonne est un `date`, et un horodatage n'y retrouverait pas la
            // ligne qu'il vient d'écrire.
            ['day' => $day->format('Y-m-d')],
            [
                'orders_completed' => $counts[YangoOrderStatus::Complete->value],
                'orders_cancelled' => $counts[YangoOrderStatus::Cancelled->value],
                'orders_other' => $counts[YangoOrderStatus::Other->value],
                'counted_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Les courses d'un statut sur la fenêtre, bornes comprises.
     */
    private function countStatus(YangoOrderStatus $status, CarbonInterface $from, CarbonInterface $to): int
    {
        return YangoOrder::query()
            ->where('status', $status)
            ->whereBetween('completed_at', [$from, $to])
            ->count();
    }
}
