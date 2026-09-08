<?php

namespace App\Services\Challenges;

use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Enums\YangoOrderStatus;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\ChallengeWinner;
use App\Models\Driver;
use App\Models\YangoOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gel du pool et exécution du tirage : fonction pure et rejouable de
 * (pool figé, graine) → gagnant(s). Aucune source d'aléatoire hors la graine
 * publiée, pour qu'un tiers puisse rejouer le tirage à l'identique.
 *
 * `mt_rand`/`mt_srand` sont utilisés délibérément à la place de `random_int` :
 * `random_int` est cryptographiquement sûr mais non rejouable, alors que
 * l'objectif ici est l'inverse — la reproductibilité prime sur le secret,
 * puisque le résultat du tirage est de toute façon publié immédiatement.
 */
class DrawService
{
    /**
     * Tickets numérotés par instruction lors du gel du vivier.
     */
    private const RANGE_CHUNK = 500;

    public function freezePool(Challenge $challenge): void
    {
        if ($challenge->status !== ChallengeStatus::Active) {
            throw new RuntimeException('Le pool ne peut être gelé que pour un challenge en cours.');
        }

        DB::transaction(function () use ($challenge): void {
            if ($challenge->isTicketBasedRaffle()) {
                $this->applyEligibilityGates($challenge);
            } else {
                $this->insertFlatEntries($challenge);
            }

            $this->assignRangeNumbers($challenge);

            $challenge->update([
                'status' => ChallengeStatus::DrawPending,
                'draw_pool_hash' => $this->computePoolHash($challenge),
            ]);
        });
    }

    public function publishSeed(Challenge $challenge): string
    {
        $seed = bin2hex(random_bytes(16));

        $challenge->update(['draw_seed' => $seed]);

        return $seed;
    }

    /**
     * @return array<int, ChallengeWinner>
     */
    public function draw(Challenge $challenge): array
    {
        if ($challenge->draw_pool_hash === null || $challenge->draw_seed === null) {
            throw new RuntimeException('Le pool et la graine doivent être publiés avant le tirage.');
        }

        mt_srand(crc32($challenge->draw_seed));

        $winners = $challenge->type === ChallengeType::Surprise
            ? $this->drawSurprise($challenge)
            : $this->drawRaffle($challenge);

        $challenge->update([
            'drawn_at' => now(),
            'status' => ChallengeStatus::PayoutPending,
        ]);

        return $winners;
    }

    /**
     * Retire du vivier les porteurs sous le seuil de courses.
     *
     * Une seule instruction : les conducteurs qui atteignent le seuil sont une
     * sous-requête agrégée sur `yango_orders (status, completed_at, driver_id)`,
     * et tout ticket d'un porteur absent de cette liste tombe — y compris celui
     * d'un conducteur sans aucune course sur la période. L'ancienne version
     * rechargeait chaque porteur puis comptait ses courses : deux requêtes par
     * conducteur, sur un vivier de plusieurs milliers.
     */
    private function applyEligibilityGates(Challenge $challenge): void
    {
        if ($challenge->min_orders === null) {
            return;
        }

        $qualifiedDrivers = YangoOrder::query()
            ->select('driver_id')
            ->where('status', YangoOrderStatus::Complete)
            ->whereBetween('completed_at', [$challenge->period_start, $challenge->period_end])
            ->groupBy('driver_id')
            ->havingRaw('count(*) >= ?', [(int) $challenge->min_orders]);

        ChallengeTicket::query()
            ->where('challenge_id', $challenge->id)
            ->whereNotIn('driver_id', $qualifiedDrivers)
            ->delete();
    }

    private function insertFlatEntries(Challenge $challenge): void
    {
        $eligibleDriverIds = Driver::query()
            ->whereHas('yangoOrders', function ($query) use ($challenge): void {
                $query->where('status', YangoOrderStatus::Complete)
                    ->whereBetween('completed_at', [$challenge->period_start, $challenge->period_end]);
            })
            ->pluck('id');

        $today = now()->toDateString();

        $rows = $eligibleDriverIds->map(fn (string $driverId): array => [
            'id' => (string) Str::ulid(),
            'challenge_id' => $challenge->id,
            'driver_id' => $driverId,
            'date' => $today,
            'created_at' => now(),
        ])->all();

        if ($rows !== []) {
            ChallengeTicket::query()->insert($rows);
        }
    }

    /**
     * Numérote les tickets de 1 à N dans l'ordre (date, id) — l'ordre que le
     * tirage rejoue.
     *
     * Par lots de `RANGE_CHUNK`, un `CASE id` par lot : une instruction pour
     * cinq cents tickets au lieu d'une par ticket, et une écriture portable
     * (MySQL comme SQLite), là où une jointure sur `row_number()` s'écrit
     * différemment selon le moteur.
     */
    private function assignRangeNumbers(Challenge $challenge): void
    {
        $ticketIds = ChallengeTicket::query()
            ->where('challenge_id', $challenge->id)
            ->orderBy('date')
            ->orderBy('id')
            ->pluck('id');

        $table = ChallengeTicket::query()->getQuery()->from;
        $rangeNumber = 1;

        foreach ($ticketIds->chunk(self::RANGE_CHUNK) as $chunk) {
            $ids = $chunk->values()->all();
            $bindings = [];

            foreach ($ids as $ticketId) {
                $bindings[] = $ticketId;
                $bindings[] = $rangeNumber++;
            }

            // Instruction écrite à la main : une expression `DB::raw` ne porte
            // pas de liaisons. Les valeurs sont toutes liées, jamais interpolées.
            DB::update(
                sprintf(
                    'update %s set range_number = case id %s end where id in (%s)',
                    $table,
                    str_repeat('when ? then ? ', count($ids)),
                    implode(', ', array_fill(0, count($ids), '?')),
                ),
                [...$bindings, ...$ids],
            );
        }
    }

    private function computePoolHash(Challenge $challenge): string
    {
        $tuples = ChallengeTicket::query()
            ->where('challenge_id', $challenge->id)
            ->orderBy('range_number')
            ->get(['id', 'driver_id', 'date', 'range_number'])
            ->map(fn (ChallengeTicket $ticket): array => [
                'id' => $ticket->id,
                'driver_id' => $ticket->driver_id,
                'date' => $ticket->date->toDateString(),
                'range_number' => $ticket->range_number,
            ])
            ->all();

        return hash('sha256', json_encode($tuples, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<int, ChallengeWinner>
     */
    private function drawRaffle(Challenge $challenge): array
    {
        $totalTickets = ChallengeTicket::query()->where('challenge_id', $challenge->id)->count();

        if ($totalTickets === 0) {
            return [];
        }

        $winningNumber = mt_rand(1, $totalTickets);

        $winningTicket = ChallengeTicket::query()
            ->where('challenge_id', $challenge->id)
            ->where('range_number', $winningNumber)
            ->firstOrFail();

        $winner = ChallengeWinner::query()->create([
            'challenge_id' => $challenge->id,
            'driver_id' => $winningTicket->driver_id,
            'prize_id' => $challenge->prize_id,
            'winning_range_number' => $winningNumber,
        ]);

        return [$winner];
    }

    /**
     * @return array<int, ChallengeWinner>
     */
    private function drawSurprise(Challenge $challenge): array
    {
        $eligibleDriverIds = Driver::query()
            ->whereHas('yangoOrders', function ($query) use ($challenge): void {
                $query->where('status', YangoOrderStatus::Complete)
                    ->whereBetween('completed_at', [$challenge->period_start, $challenge->period_end]);
            })
            ->pluck('id')
            ->all();

        $maxWinners = min($challenge->max_winners ?? 1, count($eligibleDriverIds));
        $winners = [];

        for ($i = 0; $i < $maxWinners; $i++) {
            $index = mt_rand(0, count($eligibleDriverIds) - 1);
            $driverId = $eligibleDriverIds[$index];
            unset($eligibleDriverIds[$index]);
            $eligibleDriverIds = array_values($eligibleDriverIds);

            $winners[] = ChallengeWinner::query()->create([
                'challenge_id' => $challenge->id,
                'driver_id' => $driverId,
                'amount' => $challenge->reward_amount,
            ]);
        }

        return $winners;
    }
}
