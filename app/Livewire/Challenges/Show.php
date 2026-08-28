<?php

namespace App\Livewire\Challenges;

use App\Enums\AwardMode;
use App\Enums\BackOfficeModule;
use App\Enums\ChallengeRecurrence;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Enums\OrderStatus;
use App\Enums\PrizeNature;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\ChallengeWinner;
use App\Models\Driver;
use App\Services\Challenges\DrawService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Détail d'un challenge. L'écran suit le statut : la période affiche sa
 * progression puis se fige, le tirage n'est exécutable qu'une fois le pool
 * gelé et la graine publiée, et les gratifications se pointent une par une.
 * Toute la logique d'aléatoire reste dans `DrawService`.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Challenges])]
class Show extends Component
{
    public Challenge $challenge;

    public bool $showRejectForm = false;

    public string $rejectionReason = '';

    /** Dépliage de la définition complète et de la liste des participants. */
    public bool $definitionOpen = false;

    public bool $listOpen = false;

    public string $listSearch = '';

    public string $listFilter = 'tous';

    public string $winnerSearch = '';

    public string $winnerFilter = 'tous';

    public function mount(Challenge $challenge): void
    {
        $this->challenge = $challenge;
    }

    public function approve(): void
    {
        Gate::authorize('approveSurpriseChallenge');

        $this->challenge->update([
            'status' => $this->challenge->type === ChallengeType::Leaderboard
                ? ChallengeStatus::Active
                : ChallengeStatus::Scheduled,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $this->dispatch('toast', message: __('backoffice.challenges.approved'));
    }

    public function reject(): void
    {
        Gate::authorize('approveSurpriseChallenge');

        $this->validate([
            'rejectionReason' => ['required', 'string', 'max:255'],
        ]);

        $this->challenge->update([
            'status' => ChallengeStatus::Rejected,
            'rejection_reason' => $this->rejectionReason,
        ]);

        $this->showRejectForm = false;
        $this->rejectionReason = '';

        $this->dispatch('toast', message: __('backoffice.challenges.rejected'));
    }

    /**
     * Clôture manuelle : gèle le pool et, pour un classement, passe
     * directement au dépôt des bonus puisqu'il n'y a pas de tirage.
     */
    public function closePeriod(): void
    {
        $draw = app(DrawService::class);
        $draw->freezePool($this->challenge);
        $this->challenge->refresh();

        if ($this->challenge->type === ChallengeType::Leaderboard) {
            $this->awardLeaderboard();
        } else {
            $draw->publishSeed($this->challenge);
        }

        $this->challenge->refresh();

        $this->dispatch('toast', message: __('backoffice.challenges.period_closed'));
    }

    public function regenerateSeed(): void
    {
        app(DrawService::class)->publishSeed($this->challenge);
        $this->challenge->refresh();

        $this->dispatch('toast', message: __('backoffice.challenges.seed_published'));
    }

    public function executeDraw(): void
    {
        app(DrawService::class)->draw($this->challenge);
        $this->challenge->refresh();

        $this->dispatch('toast', message: __('backoffice.challenges.drawn'));
    }

    public function markCredited(string $winnerId): void
    {
        $winner = $this->challenge->winners()->findOrFail($winnerId);

        if ($winner->credited) {
            return;
        }

        $winner->update([
            'credited' => true,
            'credited_by' => auth()->id(),
            'credited_at' => now(),
        ]);

        $this->completeIfFullyCredited();

        $this->dispatch('toast', message: __('backoffice.challenges.winner_credited'));
    }

    /**
     * Dépôt en lot : marque tous les gagnants restants comme crédités.
     */
    public function creditAll(): void
    {
        $this->challenge->winners()->where('credited', false)->update([
            'credited' => true,
            'credited_by' => auth()->id(),
            'credited_at' => now(),
        ]);

        $this->completeIfFullyCredited();

        $this->dispatch('toast', message: __('backoffice.challenges.all_credited'));
    }

    /**
     * Clé de duplication consommée par l'assistant. Le bouton émet
     * l'évènement côté navigateur (cf. la vue) : un dispatch serveur serait
     * rejoué après une navigation `wire:navigate` et rouvrirait la modale.
     */
    public function duplicateTemplateKey(): string
    {
        return 'duplicate:'.$this->challenge->id;
    }

    /**
     * Classement : les gagnants sont les N premiers par courses terminées sur
     * la période — aucun tirage n'intervient.
     */
    private function awardLeaderboard(): void
    {
        if ($this->challenge->winners()->exists()) {
            return;
        }

        $ranking = $this->rankedDrivers()->take((int) ($this->challenge->winners_count ?? 0));

        foreach ($ranking as $index => $row) {
            ChallengeWinner::query()->create([
                'challenge_id' => $this->challenge->id,
                'driver_id' => $row['driver']->id,
                'rank' => $index + 1,
                'amount' => $this->challenge->reward_amount,
            ]);
        }

        $this->challenge->update(['status' => ChallengeStatus::PayoutPending]);
    }

    private function completeIfFullyCredited(): void
    {
        if (! $this->challenge->winners()->where('credited', false)->exists()) {
            $this->challenge->update(['status' => ChallengeStatus::Completed]);
            $this->challenge->refresh();
        }
    }

    /**
     * Conducteurs classés par nombre de courses terminées sur la période.
     *
     * @return Collection<int, array{driver: Driver, orders: int, tickets: int}>
     */
    private function rankedDrivers(): Collection
    {
        $ticketCounts = $this->challenge->tickets()
            ->selectRaw('driver_id, count(*) as aggregate')
            ->groupBy('driver_id')
            ->pluck('aggregate', 'driver_id');

        return Driver::query()
            ->withCount(['orders as period_orders' => fn ($query) => $query
                ->where('status', OrderStatus::Complete)
                ->whereBetween('completed_at', [$this->challenge->period_start, $this->challenge->period_end])])
            ->get()
            ->map(fn (Driver $driver): array => [
                'driver' => $driver,
                'orders' => (int) $driver->period_orders,
                'tickets' => (int) ($ticketCounts[$driver->id] ?? 0),
            ])
            ->filter(fn (array $row): bool => $this->challenge->type === ChallengeType::Raffle
                ? $row['tickets'] > 0
                : $row['orders'] > 0)
            ->sortByDesc(fn (array $row): int => $this->challenge->type === ChallengeType::Raffle
                ? $row['tickets']
                : $row['orders'])
            ->values();
    }

    /**
     * Titre du bloc « progression » et libellé de son échéance.
     *
     * @return array{title: string, caption: string, percent: int}
     */
    public function periodProgress(): array
    {
        $isRunning = $this->challenge->status === ChallengeStatus::Active;
        $start = $this->challenge->period_start;
        $end = $this->challenge->period_end;
        // `diffInDays` renvoie un flottant : la période se compte en jours
        // pleins, bornes incluses.
        $total = max(1, (int) $start->startOfDay()->diffInDays($end->startOfDay()) + 1);
        $elapsed = $isRunning
            ? max(1, min($total, (int) $start->startOfDay()->diffInDays(now()->startOfDay()) + 1))
            : $total;

        return [
            'title' => $isRunning
                ? __('backoffice.challenges.period_progress')
                : __('backoffice.challenges.period_closed_title'),
            'caption' => $isRunning
                ? __('backoffice.challenges.period_day', ['day' => $elapsed, 'total' => $total, 'date' => $end->translatedFormat('j M')])
                : __('backoffice.challenges.period_closed_on', ['date' => $end->translatedFormat('j M')]),
            'percent' => (int) round($elapsed / $total * 100),
        ];
    }

    /**
     * Les trois compteurs sous la barre de progression : le troisième dépend
     * du type (places gagnantes, tickets émis ou population plafonnée).
     *
     * @return list<array{label: string, value: string, tone: string}>
     */
    public function progressStats(): array
    {
        $stats = [
            [
                'label' => __('backoffice.challenges.participants'),
                'value' => number_format((int) $this->challenge->participants_count, 0, ',', ' '),
                'tone' => 'text-ink',
            ],
            [
                'label' => $this->challenge->type === ChallengeType::Surprise
                    ? __('backoffice.challenges.eligibles')
                    : __('backoffice.challenges.eligible_drivers'),
                'value' => number_format($this->eligibleCount(), 0, ',', ' '),
                'tone' => 'text-primary-text',
            ],
        ];

        $stats[] = match ($this->challenge->type) {
            ChallengeType::Surprise => [
                'label' => __('backoffice.challenges.max_winning_population'),
                'value' => (string) ($this->challenge->population_max ?? 1),
                'tone' => 'text-ink',
            ],
            ChallengeType::Raffle => [
                'label' => __('backoffice.challenges.tickets_issued'),
                'value' => number_format($this->challenge->tickets()->count(), 0, ',', ' '),
                'tone' => 'text-ink',
            ],
            ChallengeType::Leaderboard => [
                'label' => __('backoffice.challenges.winning_places'),
                'value' => (string) ($this->challenge->winners_count ?? 0),
                'tone' => 'text-ink',
            ],
        };

        return $stats;
    }

    public function eligibleCount(): int
    {
        return $this->challenge->type === ChallengeType::Raffle
            ? $this->challenge->tickets()->distinct('driver_id')->count('driver_id')
            : $this->rankedDrivers()->count();
    }

    /**
     * Les quatre cases de la définition (critères, période, prix, attribution).
     *
     * @return list<array{label: string, value: string, caption: string}>
     */
    public function definitionCells(): array
    {
        $criteria = $this->challenge->activeCriteria();

        return [
            [
                'label' => __('backoffice.challenges.column_criteria'),
                'value' => trans_choice('backoffice.challenges.criteria_count', count($criteria), ['count' => count($criteria)]),
                'caption' => $this->challenge->criteriaSummary(),
            ],
            [
                'label' => __('backoffice.challenges.column_period'),
                'value' => $this->challenge->period_start->translatedFormat('j M').' → '.$this->challenge->period_end->translatedFormat('j M'),
                'caption' => match ($this->challenge->recurrence) {
                    ChallengeRecurrence::Weekly => __('backoffice.challenges.repeats_weekly'),
                    ChallengeRecurrence::Monthly => __('backoffice.challenges.repeats_monthly'),
                    ChallengeRecurrence::OneOff => __('backoffice.challenges.one_off_campaign'),
                },
            ],
            [
                'label' => __('backoffice.challenges.prize'),
                'value' => $this->challenge->prizeLabel(),
                'caption' => $this->challenge->prize_nature === PrizeNature::PhysicalItem
                    ? __('backoffice.challenges.physical_prize_caption')
                    : __('backoffice.challenges.cash_prize_caption'),
            ],
            [
                'label' => __('backoffice.challenges.award'),
                'value' => $this->challenge->award_mode === AwardMode::SingleWinner
                    ? __('backoffice.challenges.single_winner')
                    : trans_choice('backoffice.challenges.winners', $this->challenge->effectiveWinnersCount(), ['count' => $this->challenge->effectiveWinnersCount()]),
                'caption' => match (true) {
                    $this->challenge->award_mode === AwardMode::SingleWinner => __('backoffice.challenges.draw_among_eligibles'),
                    $this->challenge->type === ChallengeType::Surprise => __('backoffice.challenges.random_among_eligibles'),
                    default => __('backoffice.challenges.collective_prize_caption'),
                },
            ],
        ];
    }

    /**
     * Titre du bloc liste, selon le type.
     */
    public function listTitle(): string
    {
        return match ($this->challenge->type) {
            ChallengeType::Leaderboard => __('backoffice.challenges.full_ranking'),
            ChallengeType::Surprise => __('backoffice.challenges.eligible_drivers'),
            ChallengeType::Raffle => __('backoffice.challenges.ticket_holders'),
        };
    }

    public function listSummary(): string
    {
        $count = $this->eligibleCount();

        return match ($this->challenge->type) {
            ChallengeType::Leaderboard => __('backoffice.challenges.list_summary_ranking', [
                'drivers' => number_format($count, 0, ',', ' '),
                'places' => (int) ($this->challenge->winners_count ?? 0),
            ]),
            ChallengeType::Raffle => __('backoffice.challenges.list_summary_raffle', [
                'holders' => number_format($count, 0, ',', ' '),
                'tickets' => number_format($this->challenge->tickets()->count(), 0, ',', ' '),
            ]),
            ChallengeType::Surprise => __('backoffice.challenges.list_summary_surprise', [
                'drivers' => number_format($count, 0, ',', ' '),
            ]),
        };
    }

    /**
     * Lignes de la liste des participants, filtrées et paginées côté PHP :
     * le classement dépend d'un agrégat de courses, pas d'une colonne triable.
     *
     * @return list<array<string, mixed>>
     */
    public function listRows(): array
    {
        $winnerDriverIds = $this->challenge->winners()->pluck('driver_id')->all();
        $places = (int) ($this->challenge->winners_count ?? 0);

        $rows = $this->rankedDrivers()
            ->values()
            ->map(function (array $row, int $index) use ($winnerDriverIds, $places): array {
                $rank = $index + 1;
                $isWinner = in_array($row['driver']->id, $winnerDriverIds, true)
                    || ($this->challenge->type === ChallengeType::Leaderboard && $places > 0 && $rank <= $places);

                return [
                    'rank' => $rank,
                    'name' => $row['driver']->fullName(),
                    'account' => $row['driver']->yango_id ?? '—',
                    'orders' => $row['orders'],
                    'tickets' => $row['tickets'],
                    'isWinner' => $isWinner,
                    'label' => $isWinner
                        ? __('backoffice.challenges.winner_badge')
                        : ($this->challenge->type === ChallengeType::Leaderboard
                            ? __('backoffice.challenges.outside_top', ['top' => $places])
                            : __('backoffice.challenges.eligible_badge')),
                ];
            })
            ->all();

        if ($this->listSearch !== '') {
            $needle = mb_strtolower($this->listSearch);
            $rows = array_filter($rows, fn (array $row): bool => str_contains(mb_strtolower((string) $row['name']), $needle)
                || str_contains(mb_strtolower((string) $row['account']), $needle));
        }

        $rows = match ($this->listFilter) {
            'gagnants' => array_filter($rows, fn (array $row): bool => (bool) $row['isWinner']),
            'hors' => array_filter($rows, fn (array $row): bool => ! $row['isWinner']),
            default => $rows,
        };

        return array_slice(array_values($rows), 0, 25);
    }

    /**
     * Instantané figé du pool, tel qu'il sera rejoué depuis la graine.
     *
     * @return list<array{name: string, tickets: int, range: string, isWinner: bool}>
     */
    public function frozenPoolRows(): array
    {
        $winnerNumbers = $this->challenge->winners()->pluck('winning_range_number')->filter()->all();

        $rows = ChallengeTicket::query()
            ->where('challenge_id', $this->challenge->id)
            ->whereNotNull('range_number')
            ->with('driver')
            ->orderBy('range_number')
            ->get()
            ->groupBy('driver_id')
            ->map(function (EloquentCollection $tickets): array {
                $numbers = $tickets->pluck('range_number');
                $driver = $tickets->firstOrFail()->driver;

                return [
                    'name' => $driver->fullName(),
                    'tickets' => $tickets->count(),
                    'range' => number_format((int) $numbers->min(), 0, ',', ' ').' – '.number_format((int) $numbers->max(), 0, ',', ' '),
                    'numbers' => $numbers->all(),
                ];
            })
            ->map(fn (array $row): array => [
                'name' => $row['name'],
                'tickets' => $row['tickets'],
                'range' => $row['range'],
                'isWinner' => array_intersect($row['numbers'], $winnerNumbers) !== [],
            ])
            ->sortByDesc('isWinner')
            ->all();

        return array_slice(array_values($rows), 0, 8);
    }

    /**
     * Gagnants pour le tableau des gratifications, filtrés.
     *
     * @return Collection<int, ChallengeWinner>
     */
    public function winnerRows(): Collection
    {
        return $this->challenge->winners()
            ->with(['driver', 'prize', 'creditedBy'])
            ->when($this->winnerFilter === 'adeposer', fn ($query) => $query->where('credited', false))
            ->when($this->winnerFilter === 'deposes', fn ($query) => $query->where('credited', true))
            ->when($this->winnerSearch !== '', fn ($query) => $query->whereHas('driver', fn ($driver) => $driver
                ->where('first_name', 'like', "%{$this->winnerSearch}%")
                ->orWhere('last_name', 'like', "%{$this->winnerSearch}%")
                ->orWhere('yango_id', 'like', "%{$this->winnerSearch}%")))
            ->orderBy('rank')
            ->get();
    }

    /**
     * Message affiché à la place du tableau quand aucune gratification n'est
     * encore engagée.
     */
    public function emptyRewardsMessage(): string
    {
        return match ($this->challenge->status) {
            ChallengeStatus::PendingApproval => __('backoffice.challenges.rewards_pending_approval'),
            ChallengeStatus::Rejected => __('backoffice.challenges.rewards_rejected'),
            ChallengeStatus::DrawPending => __('backoffice.challenges.rewards_after_draw'),
            default => __('backoffice.challenges.rewards_after_close', ['date' => $this->challenge->period_end->translatedFormat('j M')]),
        };
    }

    /**
     * Budget engagé : ce que le challenge coûtera si tous les gagnants sont
     * servis.
     */
    public function committedBudget(): string
    {
        if ($this->challenge->prize_nature === PrizeNature::PhysicalItem) {
            return (string) ($this->challenge->prize->name ?? '—');
        }

        return number_format(
            (int) $this->challenge->reward_amount * $this->challenge->effectiveWinnersCount(),
            0, ',', ' '
        ).' FCFA';
    }

    public function canManageBonus(): bool
    {
        return auth('web')->user()?->hasAnyRole(['bonus', 'direction']) ?? false;
    }

    public function render(): View
    {
        // `load` et non `loadMissing` : après un tirage ou un crédit, la
        // relation déjà chargée serait périmée.
        $this->challenge->load(['prize', 'createdBy', 'winners.driver', 'winners.prize']);

        $winners = $this->winnerRows();
        $totalWinners = $this->challenge->winners()->count();
        $credited = $this->challenge->winners()->where('credited', true)->count();

        return view('livewire.challenges.show', [
            'challenge' => $this->challenge,
            'progress' => $this->periodProgress(),
            'stats' => $this->progressStats(),
            'definition' => $this->definitionCells(),
            'winners' => $winners,
            'totalWinners' => $totalWinners,
            'creditedCount' => $credited,
            'canManage' => $this->canManageBonus(),
        ]);
    }
}
