<?php

namespace App\Http\Resources;

use App\Models\Challenge;
use App\Models\Driver;
use App\Services\Challenges\DriverProgressService;
use Illuminate\Support\Facades\Storage;

/**
 * Charge utile d'un challenge en cours vu par un conducteur donné.
 *
 * Ce n'est pas une `JsonResource` : la charge utile est une composition — le
 * challenge plus la progression propre au conducteur, calculée par
 * `DriverProgressService` — et non la projection d'un modèle. En faire une
 * ressource exposerait le modèle `Challenge` lui-même (`draw_seed`,
 * `min_rating_enabled`…) au lieu du contrat réel, qui est publié dans
 * `docs/api/paths/challenges.yaml`.
 */
class DriverChallengePayload
{
    /**
     * Charge utile d'un challenge pour ce conducteur. Les blocs `ticketing`,
     * `leaderboard` et `won` sont omis lorsqu'ils ne s'appliquent pas.
     *
     * @return array<string, mixed>
     */
    public static function build(
        Challenge $challenge,
        Driver $driver,
        DriverProgressService $progress,
    ): array {
        $payload = [
            'id' => $challenge->id,
            /**
             * Référence affichable du challenge.
             *
             * @example CH-2026-039
             */
            'reference' => $challenge->reference,
            'name' => $challenge->name,
            /**
             * @var 'leaderboard'|'raffle'|'surprise'
             */
            'type' => $challenge->type->value,
            /**
             * @var 'active'|'draw_pending'|'payout_pending'
             */
            'status' => $challenge->status->value,
            'criteria_summary' => $challenge->criteriaSummary(),
            'period' => [
                'start' => $challenge->period_start->toDateString(),
                'end' => $challenge->period_end->toDateString(),
                'week_iso' => $challenge->week_iso,
            ],
            'prize' => $challenge->prize === null ? null : [
                'name' => $challenge->prize->name,
                'photo_url' => $challenge->prize->photo_url === null
                    ? null
                    : Storage::url($challenge->prize->photo_url),
            ],
        ];

        $ticketing = $progress->ticketing($driver, $challenge);

        if ($ticketing !== null) {
            $payload['ticketing'] = $ticketing;
        }

        $leaderboard = $progress->leaderboard($driver, $challenge);

        if ($leaderboard !== null) {
            $payload['leaderboard'] = $leaderboard;
        }

        $won = $progress->win($driver, $challenge);

        if ($won !== null) {
            // La consigne de retrait ne vaut que pour un lot physique : un
            // bonus en cash est crédité sur le compte Yango, il n'y a rien à
            // venir chercher.
            $payload['won'] = $won['prize_name'] === null
                ? $won
                : [...$won, 'collection_note' => __('api.prize_collection_note')];
        }

        return $payload;
    }
}
