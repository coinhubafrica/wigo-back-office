<?php

namespace App\Services\Challenges;

use App\Enums\ChallengeType;
use App\Enums\YangoOrderStatus;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\YangoOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Progression d'un conducteur sur un challenge, telle que l'application
 * mobile l'affiche.
 *
 * Tout est calculé par requête agrégée : contrairement au tableau du
 * back-office, qui classe une poignée de conducteurs en mémoire, l'API répond
 * pour un seul conducteur sur un parc entier — charger tout le parc pour en
 * extraire un rang ne passerait pas à l'échelle.
 */
class DriverProgressService
{
    /**
     * Courses terminées par le conducteur sur la période du challenge.
     */
    public function completedOrders(Driver $driver, Challenge $challenge): int
    {
        return YangoOrder::query()
            ->where('driver_id', $driver->id)
            ->where('status', YangoOrderStatus::Complete)
            ->whereBetween('completed_at', [$challenge->period_start, $challenge->period_end])
            ->count();
    }

    /**
     * Tickets détenus par le conducteur sur ce challenge, du plus ancien au
     * plus récent.
     *
     * `range_number` reste `null` tant que le vivier du tirage n'est pas gelé :
     * le numéro n'est attribué qu'à ce moment-là, et c'est lui que le
     * conducteur compare au ticket gagnant.
     *
     * @return list<array{id: string, date: string, range_number: int|null}>
     */
    public function tickets(Driver $driver, Challenge $challenge): array
    {
        return ChallengeTicket::query()
            ->where('challenge_id', $challenge->id)
            ->where('driver_id', $driver->id)
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'date', 'range_number'])
            ->map(fn (ChallengeTicket $ticket): array => [
                'id' => $ticket->id,
                'date' => $ticket->date->toDateString(),
                'range_number' => $ticket->range_number,
            ])
            ->all();
    }

    /**
     * Progression vers le prochain ticket : où en est le conducteur dans la
     * tranche en cours, et combien de courses lui manquent.
     *
     * @return array{
     *     trips_per_ticket: int,
     *     orders_completed: int,
     *     tickets_held: int,
     *     progress_in_block: int,
     *     orders_to_next_ticket: int,
     *     tickets: list<array{id: string, date: string, range_number: int|null}>,
     * }|null  null si le challenge n'attribue pas de tickets
     */
    public function ticketing(Driver $driver, Challenge $challenge): ?array
    {
        $ratio = (int) $challenge->trips_per_ticket;

        if (! $challenge->isTicketBasedRaffle() || $ratio <= 0) {
            return null;
        }

        $orders = $this->completedOrders($driver, $challenge);
        $progress = $orders % $ratio;
        // La liste sert aussi de compteur : les tickets d'un conducteur sur un
        // challenge se comptent sur les doigts, inutile d'ajouter un `count()`.
        $tickets = $this->tickets($driver, $challenge);

        return [
            'trips_per_ticket' => $ratio,
            'orders_completed' => $orders,
            'tickets_held' => count($tickets),
            'progress_in_block' => $progress,
            'orders_to_next_ticket' => $ratio - $progress,
            'tickets' => $tickets,
        ];
    }

    /**
     * Rang du conducteur au classement : le nombre de conducteurs qui le
     * devancent, plus un. Une seule requête agrégée, sans trier le parc.
     *
     * Renvoie null si le conducteur n'a aucune course sur la période — il
     * n'est alors pas classé.
     */
    public function rank(Driver $driver, Challenge $challenge): ?int
    {
        $orders = $this->completedOrders($driver, $challenge);

        if ($orders === 0) {
            return null;
        }

        // Sous-requête : les conducteurs comptant plus de courses que lui.
        // Le dénombrement reste en base — le parc entier ne remonte jamais en
        // mémoire.
        $ahead = YangoOrder::query()
            ->where('status', YangoOrderStatus::Complete)
            ->whereBetween('completed_at', [$challenge->period_start, $challenge->period_end])
            ->where('driver_id', '!=', $driver->id)
            ->groupBy('driver_id')
            ->havingRaw('count(*) > ?', [$orders])
            ->selectRaw('driver_id');

        return DB::query()->fromSub($ahead, 'ahead')->count() + 1;
    }

    /**
     * Historique hebdomadaire des courses terminées, du plus ancien au plus
     * récent, la semaine en cours en dernier.
     *
     * S'appuie sur le cumul journalier tenu par `DailyActivityService` plutôt
     * que de réagréger la table des courses.
     *
     * @return list<array{week_iso: string, label: string, orders_completed: int, current: bool}>
     */
    public function weeklyHistory(Driver $driver, int $weeks = 12): array
    {
        $currentWeekStart = Carbon::now()->startOfWeek();
        $oldestWeekStart = $currentWeekStart->copy()->subWeeks($weeks - 1);

        $totals = DriverDailyActivity::query()
            ->where('driver_id', $driver->id)
            ->where('activity_date', '>=', $oldestWeekStart->toDateString())
            ->get()
            ->groupBy(fn (DriverDailyActivity $activity): string => $activity->activity_date->format('o-\WW'))
            ->map(fn ($group): int => (int) $group->sum('orders_completed'));

        $history = [];

        for ($offset = $weeks - 1; $offset >= 0; $offset--) {
            $weekStart = $currentWeekStart->copy()->subWeeks($offset);
            $weekIso = $weekStart->format('o-\WW');

            $history[] = [
                'week_iso' => $weekIso,
                // « S-0 » est la semaine en cours, « S-11 » la plus ancienne :
                // les libellés de l'axe du graphique mobile.
                'label' => 'S-'.$offset,
                'orders_completed' => $totals[$weekIso] ?? 0,
                'current' => $offset === 0,
            ];
        }

        return $history;
    }

    /**
     * Gain du conducteur sur ce challenge, s'il en a un.
     *
     * @return array{
     *     drawn_at: string|null,
     *     prize_name: string|null,
     *     amount: int|null,
     *     credited: bool,
     * }|null
     */
    public function win(Driver $driver, Challenge $challenge): ?array
    {
        $winner = $challenge->winners()
            ->with('prize')
            ->where('driver_id', $driver->id)
            ->first();

        if ($winner === null) {
            return null;
        }

        return [
            'drawn_at' => $challenge->drawn_at?->toIso8601String(),
            'prize_name' => $winner->prize->name ?? $challenge->prize?->name,
            'amount' => $winner->amount,
            'credited' => (bool) $winner->credited,
        ];
    }

    /**
     * Détail du classement affiché par l'application.
     *
     * @return array{rank: int|null, winning_places: int, reward_amount: int|null, in_winning_range: bool}|null
     */
    public function leaderboard(Driver $driver, Challenge $challenge): ?array
    {
        if ($challenge->type !== ChallengeType::Leaderboard) {
            return null;
        }

        $rank = $this->rank($driver, $challenge);
        $places = (int) ($challenge->winners_count ?? 0);

        return [
            'rank' => $rank,
            'winning_places' => $places,
            'reward_amount' => $challenge->reward_amount,
            'in_winning_range' => $rank !== null && $places > 0 && $rank <= $places,
        ];
    }
}
