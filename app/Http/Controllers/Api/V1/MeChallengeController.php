<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChallengeStatus;
use App\Http\Controllers\Concerns\ResolvesDriver;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverChallengePayload;
use App\Models\Challenge;
use App\Services\Challenges\DriverProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeChallengeController extends Controller
{
    use ResolvesDriver;

    public function __construct(private DriverProgressService $progress) {}

    /**
     * Challenges en cours du conducteur
     *
     * Un élément par challenge ouvert, avec la progression propre au
     * conducteur : tickets détenus et courses restantes avant le prochain pour
     * une tombola, rang et prime pour un classement, gain éventuel. Les blocs
     * `ticketing`, `leaderboard` et `won` ne sont présents que lorsqu'ils
     * s'appliquent au challenge.
     *
     * `meta.weekly_history` porte les douze dernières semaines de courses
     * terminées, de la plus ancienne à la semaine en cours.
     *
     * Reste accessible à un conducteur suspendu, comme le profil : ses
     * compteurs cessent simplement de progresser.
     *
     * @response array{
     *     message: string,
     *     data: array<int, array{
     *         id: string,
     *         reference: string,
     *         name: string,
     *         type: 'leaderboard'|'raffle'|'surprise',
     *         status: 'active'|'draw_pending'|'payout_pending',
     *         criteria_summary: string,
     *         period: array{start: string, end: string, week_iso: string|null},
     *         prize: array{name: string, photo_url: string|null}|null,
     *         ticketing?: array{
     *             trips_per_ticket: int,
     *             orders_completed: int,
     *             tickets_held: int,
     *             progress_in_block: int,
     *             orders_to_next_ticket: int,
     *         },
     *         leaderboard?: array{
     *             rank: int|null,
     *             winning_places: int,
     *             reward_amount: int|null,
     *             in_winning_range: bool,
     *         },
     *         won?: array{
     *             drawn_at: string|null,
     *             prize_name: string|null,
     *             amount: int|null,
     *             credited: bool,
     *             collection_note?: string,
     *         },
     *     }>,
     *     meta: array{
     *         weekly_history: array<int, array{
     *             week_iso: string,
     *             label: string,
     *             orders_completed: int,
     *             current: bool,
     *         }>,
     *     },
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $challenges = Challenge::query()
            ->with([
                'prize',
                // Seul le gain de ce conducteur nous intéresse : inutile de
                // charger tous les gagnants du challenge.
                'winners' => fn ($query) => $query->where('driver_id', $driver->id)->with('prize'),
            ])
            ->whereIn('status', [
                ChallengeStatus::Active,
                ChallengeStatus::DrawPending,
                ChallengeStatus::PayoutPending,
            ])
            ->orderByDesc('period_start')
            ->get();

        $data = $challenges
            ->map(fn (Challenge $challenge): array => DriverChallengePayload::build(
                $challenge,
                $driver,
                $this->progress,
            ))
            ->all();

        return $this->okApiResponse(
            $data,
            meta: ['weekly_history' => $this->progress->weeklyHistory($driver)],
        );
    }
}
