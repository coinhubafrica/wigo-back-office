<?php

namespace App\Services\Challenges;

use App\Enums\YangoOrderStatus;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Cumul journalier d'activité d'un conducteur.
 *
 * Le grand livre et l'émission des tickets ont été séparés. La ligne du jour
 * garde le compte des courses terminées et le cumul de carrière, qui
 * alimentent l'historique par semaine ISO du mobile et le tableau de bord ;
 * les tickets, eux, se comptent sur la période du challenge et vivent dans
 * `ChallengeTicketMinter`.
 *
 * Le cumul `orders_total` n'est donc plus la base d'un calcul de ticket : il
 * l'était, et un conducteur arrivant avec un compteur déjà élevé gagnait un
 * ticket dès sa première course. Il reste ce qu'il a toujours dit — combien de
 * courses ce conducteur a terminées depuis toujours.
 */
class DailyActivityService
{
    public function __construct(private readonly ChallengeTicketMinter $minter) {}

    public function recordDay(Driver $driver, CarbonInterface $date): void
    {
        DB::transaction(function () use ($driver, $date): void {
            // Bornes de la journée et non `whereDate()` : `DATE(completed_at)`
            // rendrait l'index `(driver_id, status, completed_at)` inutilisable
            // et relirait toutes les courses terminées du conducteur.
            $ordersCompleted = $driver->yangoOrders()
                ->where('status', YangoOrderStatus::Complete)
                ->whereBetween('completed_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
                ->count();

            $previousDay = DriverDailyActivity::query()
                ->where('driver_id', $driver->id)
                ->where('activity_date', '<', $date->toDateString())
                ->orderByDesc('activity_date')
                ->first();

            $previousTotal = $previousDay->orders_total ?? 0;
            $ordersTotal = $previousTotal + $ordersCompleted;

            // `activity_date` est une colonne `date` : la clé de recherche doit
            // l'être aussi. Passer un horodatage y insérait « 2026-09-03
            // 00:00:00 », que la recherche suivante ne retrouvait pas — un
            // second appel sur la même journée violait alors l'unicité au lieu
            // de mettre la ligne à jour. Invisible tant que personne ne
            // rejouait un jour ; la synchronisation des courses, elle, rejoue.
            DriverDailyActivity::query()->updateOrCreate(
                ['driver_id' => $driver->id, 'activity_date' => $date->format('Y-m-d')],
                ['orders_completed' => $ordersCompleted, 'orders_total' => $ordersTotal],
            );
        });

        // Hors transaction : le minter tient la sienne, par conducteur et sous
        // verrou, et notifie après commit.
        $this->minter->mintForDriverOn($driver, $date);
    }
}
