<?php

namespace App\Services\Challenges;

use App\Enums\YangoOrderStatus;
use App\Models\DriverDailyActivity;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reconstruit le cumul journalier d'une journée à partir de `yango_orders`,
 * sans rien redemander à Yango.
 *
 * Pourquoi cette classe existe, à côté de `DailyActivityService::recordDay()`
 * qui sait déjà compter une journée : `recordDay()` vise **un** conducteur et
 * relit la veille pour chaîner `orders_total`. Rejouer une journée de tout le
 * parc par ce chemin, c'est treize mille transactions et deux requêtes
 * chacune. Ici la journée se recompte en une agrégation, et l'écriture se fait
 * par lots.
 *
 * Ce qui a rendu la classe nécessaire : les courses s'écrivaient au fil du
 * curseur alors que le cumul se recalculait **après** la boucle. Une passe qui
 * tombait au milieu — le plus souvent sur une `PDOException` de téléphone
 * dupliqué, désormais corrigée dans `YangoSyncService::syncDriver()` — laissait
 * les courses en base et le cumul absent. Dix-sept journées étaient dans cet
 * état en préproduction, dont 2026-09-12 : 9 255 courses terminées, 1 740 au
 * tableau de bord.
 *
 * Deux règles à ne pas défaire :
 *
 * - **Seul `status = complete` compte.** C'est ce que `recordDay()` a toujours
 *   fait, et ce qui distingue le tableau de bord du décompte brut de l'écran de
 *   rattrapage : une journée porte volontiers autant d'annulées que de
 *   terminées.
 * - **`orders_total` est un cumul de carrière**, donc chaîné : réécrire une
 *   journée fausse toutes les suivantes du même conducteur tant qu'elles ne
 *   sont pas réécrites aussi. D'où `repairTotalsFrom()`, et d'où l'ordre
 *   chronologique imposé au rattrapage d'une période.
 */
class DailyActivityRebuilder
{
    /**
     * Nombre de lignes écrites par lot. Un parc de treize mille conducteurs
     * tient en quelques requêtes plutôt qu'en treize mille.
     */
    private const CHUNK = 500;

    /**
     * Recompte la journée pour tout le parc et rend le nombre de conducteurs
     * touchés.
     *
     * Les conducteurs qui n'ont aucune course terminée ce jour-là ne reçoivent
     * pas de ligne à zéro : l'absence de ligne dit déjà « pas roulé », et en
     * créer treize mille par journée gonflerait la table sans rien apprendre.
     * En revanche, une ligne **existante** qui ne correspond plus à aucune
     * course est remise à zéro — sans quoi une course supprimée chez Yango
     * resterait comptée pour toujours.
     */
    public function rebuildDay(CarbonInterface $day): int
    {
        $date = $day->format('Y-m-d');

        $counts = DB::table('yango_orders')
            ->where('status', YangoOrderStatus::Complete->value)
            ->whereBetween('completed_at', [
                $day->copy()->startOfDay(),
                $day->copy()->endOfDay(),
            ])
            ->selectRaw('driver_id, count(*) as total')
            ->groupBy('driver_id')
            ->pluck('total', 'driver_id');

        $now = Carbon::now();
        $touched = 0;

        foreach ($counts->chunk(self::CHUNK) as $chunk) {
            $touched += $this->upsert($chunk->all(), $date, $now);
        }

        $this->zeroStaleRows($date, $counts->keys()->all());

        return $touched;
    }

    /**
     * Écrit un lot de comptes journaliers.
     *
     * `upsert()` plutôt que `updateOrCreate()` ligne à ligne : une seule
     * requête pour cinq cents conducteurs. `orders_total` n'est **pas** touché
     * ici — il se répare ensuite, en une passe chaînée par conducteur.
     *
     * @param  array<string, int>  $counts
     */
    private function upsert(array $counts, string $date, Carbon $now): int
    {
        if ($counts === []) {
            return 0;
        }

        $rows = [];

        foreach ($counts as $driverId => $total) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'driver_id' => (string) $driverId,
                'activity_date' => $date,
                'orders_completed' => (int) $total,
                'orders_total' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('driver_daily_activities')->upsert(
            $rows,
            ['driver_id', 'activity_date'],
            ['orders_completed', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * Remet à zéro les lignes de la journée qui ne correspondent plus à aucune
     * course terminée.
     *
     * La ligne n'est pas supprimée : `orders_total` la traverse, et une
     * suppression obligerait la réparation du cumul à raisonner sur des trous.
     *
     * @param  list<mixed>  $keptDriverIds
     */
    private function zeroStaleRows(string $date, array $keptDriverIds): void
    {
        DB::table('driver_daily_activities')
            ->where('activity_date', $date)
            ->where('orders_completed', '>', 0)
            ->when(
                $keptDriverIds !== [],
                fn ($query) => $query->whereNotIn('driver_id', $keptDriverIds),
            )
            ->update(['orders_completed' => 0, 'updated_at' => Carbon::now()]);
    }

    /**
     * Rechaîne `orders_total` à partir de cette journée, pour les conducteurs
     * qui ont une ligne à cette date ou après.
     *
     * Le cumul de carrière est une somme courante : corriger le 12 sans
     * rechaîner le 13 laisserait le tableau de bord juste et les tickets faux.
     * On repart donc du dernier total **antérieur** à la journée, réputé bon,
     * puis on additionne dans l'ordre.
     *
     * Rend le nombre de lignes réécrites.
     */
    public function repairTotalsFrom(CarbonInterface $day): int
    {
        $date = $day->format('Y-m-d');
        $repaired = 0;

        DriverDailyActivity::query()
            ->where('activity_date', '>=', $date)
            ->select('driver_id')
            ->distinct()
            ->pluck('driver_id')
            ->chunk(self::CHUNK)
            ->each(function ($driverIds) use ($date, &$repaired): void {
                foreach ($driverIds as $driverId) {
                    $repaired += $this->repairDriverTotals((string) $driverId, $date);
                }
            });

        return $repaired;
    }

    /**
     * Rechaîne le cumul d'un conducteur à partir d'une date.
     *
     * Une seule écriture par ligne réellement changée : un conducteur dont le
     * cumul est déjà juste ne coûte qu'une lecture.
     */
    private function repairDriverTotals(string $driverId, string $date): int
    {
        $running = (int) (DriverDailyActivity::query()
            ->where('driver_id', $driverId)
            ->where('activity_date', '<', $date)
            ->orderByDesc('activity_date')
            ->value('orders_total') ?? 0);

        $repaired = 0;

        DriverDailyActivity::query()
            ->where('driver_id', $driverId)
            ->where('activity_date', '>=', $date)
            ->orderBy('activity_date')
            ->each(function (DriverDailyActivity $row) use (&$running, &$repaired): void {
                $running += $row->orders_completed;

                if ($row->orders_total !== $running) {
                    $row->orders_total = $running;
                    $row->save();
                    $repaired++;
                }
            });

        return $repaired;
    }
}
