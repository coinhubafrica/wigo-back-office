<?php

namespace App\Services\Challenges;

use App\Enums\YangoOrderStatus;
use App\Models\Challenge;
use App\Models\YangoOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Combien de conducteurs distincts ont terminé une course sur la période d'un
 * challenge — la seule définition de « participant », puisqu'il n'y a pas
 * d'inscription (cf. `.ai/rules/challenges.md`).
 *
 * Les bornes se lient en **constantes**, jamais en colonnes de la table
 * `challenges`. C'est tout le sujet : la liste posait le compte en
 * sous-requête corrélée (`whereColumn('yango_orders.completed_at', '>=',
 * 'challenges.period_start')`), et MySQL ne sait pas faire d'une colonne de la
 * ligne externe une borne d'index. Le plan retombait sur la seule première
 * colonne de `yango_orders (status, completed_at, driver_id)` :
 *
 *     Covering index lookup ... (status = 'complete')
 *       -> Filter: (completed_at >= challenges.period_start and ...)
 *
 * Chacune des 20 lignes de la page relisait donc **tout l'historique des
 * courses terminées** — 1,8 M de lignes pour une période qui en contient 97 k,
 * dix-neuf fois trop —, et l'écran tombait en 504 en préproduction. Les mêmes
 * bornes passées en paramètres rendent la plage utilisable :
 *
 *     Covering index range scan ... over (status = 'complete'
 *       AND '2026-08-30 14:00:55' <= completed_at <= '2026-09-05 14:00:55')
 *
 * Mesuré sur 2 M de courses : 14,5 s pour une page de 20, contre 0,9 s ici.
 *
 * Ne pas revenir à une sous-requête corrélée, ni passer par `whereDate()` —
 * `DATE(completed_at)` écarterait l'index tout aussi sûrement.
 */
class ParticipantCounter
{
    /**
     * Le compte de toute une page, une requête par challenge et pas une de
     * plus. Le gabarit est préparé une fois, seules les bornes changent.
     *
     * @param  iterable<Challenge>  $challenges
     * @return array<string, int> indexé par identifiant de challenge
     */
    public function countForPeriods(iterable $challenges): array
    {
        return (new Collection($challenges))
            ->mapWithKeys(fn (Challenge $challenge): array => [
                $challenge->id => $this->countForPeriod($challenge->period_start, $challenge->period_end),
            ])
            ->all();
    }

    /**
     * Le compte d'une seule période.
     */
    public function countForPeriod(CarbonInterface $start, CarbonInterface $end): int
    {
        return YangoOrder::query()
            ->where('status', YangoOrderStatus::Complete)
            ->whereBetween('completed_at', [$start, $end])
            ->distinct()
            ->count('driver_id');
    }
}
