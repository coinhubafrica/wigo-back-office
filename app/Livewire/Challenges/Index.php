<?php

namespace App\Livewire\Challenges;

use App\Enums\BackOfficeModule;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Models\Challenge;
use App\Models\ChallengeWinner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Liste des challenges : KPI de pilotage, modèles pré-remplis, file filtrable.
 * L'assistant de création est un composant enfant affiché en modale.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Challenges])]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'tous';

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    /**
     * Rafraîchit la liste après création d'un challenge par l'assistant.
     */
    #[On('challenge-created')]
    public function refreshList(): void
    {
        $this->resetPage();
    }

    /**
     * Les filtres du prototype : trois par statut, trois par type.
     *
     * @return array<string, array{label: string, apply: callable}>
     */
    private function filters(): array
    {
        return [
            'tous' => ['label' => __('backoffice.challenges.filter_tous'), 'apply' => fn (Builder $q) => $q],
            'actif' => ['label' => __('backoffice.challenges.filter_actif'), 'apply' => fn (Builder $q) => $q->whereIn('status', ChallengeStatus::running())],
            'action' => ['label' => __('backoffice.challenges.filter_action'), 'apply' => fn (Builder $q) => $q->whereIn('status', ChallengeStatus::requiringAction())],
            'classement' => ['label' => __('backoffice.challenges.filter_classement'), 'apply' => fn (Builder $q) => $q->where('type', ChallengeType::Leaderboard)],
            'tirage' => ['label' => __('backoffice.challenges.filter_tirage'), 'apply' => fn (Builder $q) => $q->where('type', ChallengeType::Raffle)],
            'surprise' => ['label' => __('backoffice.challenges.filter_surprise'), 'apply' => fn (Builder $q) => $q->where('type', ChallengeType::Surprise)],
            'termine' => ['label' => __('backoffice.challenges.filter_termine'), 'apply' => fn (Builder $q) => $q->whereIn('status', ChallengeStatus::finished())],
        ];
    }

    public function render(): View
    {
        $filters = $this->filters();
        $active = $filters[$this->filter] ?? $filters['tous'];

        $challenges = ($active['apply'])(Challenge::query()->with('prize'))
            ->latest('period_start')
            ->paginate(20);

        $chips = [];

        foreach ($filters as $key => $definition) {
            $chips[] = [
                'key' => $key,
                'label' => $definition['label'],
                'count' => ($definition['apply'])(Challenge::query())->count(),
            ];
        }

        return view('livewire.challenges.index', [
            'challenges' => $challenges,
            'chips' => $chips,
            'kpis' => $this->kpis(),
        ]);
    }

    /**
     * KPI de tête (source : prototype, `chKpis`).
     *
     * `tone` est une teinte nommée du composant `x-kpi-card`, jamais une classe.
     *
     * @return list<array{value: string, label: string, detail: string, tone: string}>
     */
    private function kpis(): array
    {
        $total = Challenge::query()->count();
        $running = Challenge::query()->whereIn('status', ChallengeStatus::running())->count();
        $actionRequired = Challenge::query()->whereIn('status', ChallengeStatus::requiringAction())->count();
        $toDeposit = ChallengeWinner::query()->where('credited', false)->count();
        $rewarded = ChallengeWinner::query()->where('credited', true)->count();
        $budget = (int) ChallengeWinner::query()->where('credited', true)->sum('amount');

        return [
            [
                'value' => (string) $running,
                'label' => __('backoffice.challenges.kpi_running'),
                'detail' => trans_choice('backoffice.challenges.kpi_running_detail', $total, ['count' => $total]),
                'tone' => 'primary',
            ],
            [
                'value' => (string) $actionRequired,
                'label' => __('backoffice.challenges.kpi_action_required'),
                'detail' => __('backoffice.challenges.kpi_action_required_detail'),
                'tone' => $actionRequired > 0 ? 'warn' : 'neutral',
            ],
            [
                'value' => (string) $toDeposit,
                'label' => __('backoffice.challenges.kpi_to_deposit'),
                'detail' => __('backoffice.challenges.kpi_to_deposit_detail'),
                'tone' => $toDeposit > 0 ? 'warn' : 'ok',
            ],
            [
                'value' => number_format($rewarded, 0, ',', ' '),
                'label' => __('backoffice.challenges.kpi_rewarded'),
                'detail' => __('backoffice.challenges.kpi_rewarded_detail', ['amount' => number_format($budget, 0, ',', ' ')]),
                'tone' => 'ok',
            ],
        ];
    }
}
