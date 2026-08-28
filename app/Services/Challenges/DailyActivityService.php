<?php

namespace App\Services\Challenges;

use App\Enums\ChallengeStatus;
use App\Enums\OrderStatus;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cumul journalier d'activité d'un chauffeur et émission des tickets de
 * challenge. Les tickets sont gagnés au fil de l'eau, chaque fois que le
 * total cumulé de courses franchit un multiple de `trips_per_ticket`, et non
 * recalculés en une fois en fin de période — un challenge ouvert affiche donc
 * toujours un pool à jour.
 */
class DailyActivityService
{
    public function recordDay(Driver $driver, CarbonInterface $date): void
    {
        DB::transaction(function () use ($driver, $date): void {
            $ordersCompleted = $driver->orders()
                ->where('status', OrderStatus::Complete)
                ->whereDate('completed_at', $date)
                ->count();

            $previousDay = DriverDailyActivity::query()
                ->where('driver_id', $driver->id)
                ->where('activity_date', '<', $date->toDateString())
                ->orderByDesc('activity_date')
                ->first();

            $previousTotal = $previousDay?->orders_total ?? 0;
            $ordersTotal = $previousTotal + $ordersCompleted;

            DriverDailyActivity::query()->updateOrCreate(
                ['driver_id' => $driver->id, 'activity_date' => $date->toDateString()],
                ['orders_completed' => $ordersCompleted, 'orders_total' => $ordersTotal],
            );

            $this->mintTickets($driver, $date, $previousTotal, $ordersTotal);
        });
    }

    private function mintTickets(Driver $driver, CarbonInterface $date, int $previousTotal, int $currentTotal): void
    {
        $challenges = Challenge::query()
            ->where('is_ticket_based', true)
            ->whereIn('status', [ChallengeStatus::Active, ChallengeStatus::DrawPending])
            ->where('period_start', '<=', $date)
            ->where('period_end', '>=', $date)
            ->get();

        foreach ($challenges as $challenge) {
            $tripsPerTicket = $challenge->trips_per_ticket;

            if ($tripsPerTicket === null || $tripsPerTicket === 0) {
                continue;
            }

            $previousTickets = intdiv($previousTotal, $tripsPerTicket);
            $currentTickets = intdiv($currentTotal, $tripsPerTicket);
            $newlyEarned = $currentTickets - $previousTickets;

            if ($newlyEarned <= 0) {
                continue;
            }

            // Idempotence : ne pas re-miner si cette journée a déjà été
            // traitée pour ce chauffeur sur ce challenge (un retry ne doit
            // jamais doubler les tickets).
            $alreadyMinted = $challenge->tickets()
                ->where('driver_id', $driver->id)
                ->where('date', $date->toDateString())
                ->exists();

            if ($alreadyMinted) {
                continue;
            }

            $rows = array_map(fn (): array => [
                'id' => (string) Str::ulid(),
                'challenge_id' => $challenge->id,
                'driver_id' => $driver->id,
                'date' => $date->toDateString(),
                'created_at' => now(),
            ], range(1, $newlyEarned));

            ChallengeTicket::query()->insert($rows);
        }
    }
}
