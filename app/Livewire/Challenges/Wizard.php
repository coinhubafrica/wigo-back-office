<?php

namespace App\Livewire\Challenges;

use App\Enums\AwardMode;
use App\Enums\ChallengeRecurrence;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Enums\PrizeNature;
use App\Models\Challenge;
use App\Models\Prize;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Assistant de création affiché en modale au-dessus de la liste : quatre
 * étapes (type, critères, période, prix) et un récapitulatif permanent qui
 * chiffre les éligibles et le coût maximal au fil de la saisie.
 */
class Wizard extends Component
{
    public bool $open = false;

    public int $step = 1;

    public const LAST_STEP = 4;

    public string $name = '';

    public string $type = 'leaderboard';

    public string $recurrence = 'hebdo';

    public string $periodStart = '';

    public string $periodEnd = '';

    // Critères : interrupteur + valeur, comme dans le prototype.
    public bool $minOrdersEnabled = true;

    public ?int $minOrders = 50;

    public bool $topNEnabled = true;

    public ?int $topN = 100;

    public bool $minAcceptanceRateEnabled = false;

    public ?int $minAcceptanceRate = 85;

    public bool $minRatingEnabled = false;

    public ?string $minRating = '4.5';

    public bool $minActiveDaysEnabled = false;

    public ?int $minActiveDays = 5;

    // Prix et attribution.
    public string $prizeNature = 'cash';

    public ?int $rewardAmount = 5_000;

    public ?string $prizeId = null;

    public string $awardMode = 'collectif';

    public ?int $winnersCount = 100;

    public ?int $populationMax = 3;

    /**
     * Le ticketing est une option du tirage, décochée par défaut : un tirage
     * simple donne une entrée par conducteur éligible.
     */
    public bool $isTicketBased = false;

    public ?int $tripsPerTicket = 50;

    public function mount(): void
    {
        $this->resetPeriod();
    }

    /**
     * Appelé depuis la liste via un écouteur Alpine sur `window` (cf. la vue) :
     * un dispatch Livewire depuis le parent re-rendrait la liste et écraserait
     * l'état de la modale au moment même où elle s'ouvre.
     */
    public function openWizard(?string $template = null): void
    {
        $this->resetWizard();

        if ($template !== null) {
            $this->applyTemplate($template);
        }

        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetValidation();
    }

    public function nextStep(): void
    {
        $this->validate($this->rulesForStep($this->step));

        $this->step = min($this->step + 1, self::LAST_STEP);
    }

    public function previousStep(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function selectType(string $type): void
    {
        $this->type = $type;

        // Le type impose sa mécanique d'attribution : une tombola désigne un
        // gagnant unique, un bonus surprise tire dans une population plafonnée.
        if ($type === ChallengeType::Raffle->value) {
            $this->awardMode = AwardMode::SingleWinner->value;
            $this->prizeNature = PrizeNature::PhysicalItem->value;
            $this->topNEnabled = false;
        }

        if ($type === ChallengeType::Surprise->value) {
            $this->awardMode = AwardMode::Collective->value;
            $this->prizeNature = PrizeNature::Cash->value;
            $this->topNEnabled = false;
            $this->recurrence = ChallengeRecurrence::OneOff->value;
        }

        if ($type === ChallengeType::Leaderboard->value) {
            $this->awardMode = AwardMode::Collective->value;
            $this->prizeNature = PrizeNature::Cash->value;
            $this->topNEnabled = true;
        }

        // Le ticketing n'existe que pour le tirage au sort.
        if ($type !== ChallengeType::Raffle->value) {
            $this->isTicketBased = false;
        }

        $this->applyTicketingConstraints();
    }

    /**
     * Un tirage à tickets ne se règle que sur le nombre de courses : la
     * tranche de courses par ticket EST le critère, et sert aussi de seuil
     * d'entrée (moins d'une tranche complète = aucun ticket, donc hors pool).
     * Les autres critères sont donc désactivés.
     */
    public function updatedIsTicketBased(): void
    {
        $this->applyTicketingConstraints();
    }

    private function applyTicketingConstraints(): void
    {
        if (! $this->isTicketBasedRaffle()) {
            return;
        }

        $this->minOrdersEnabled = true;
        $this->topNEnabled = false;
        $this->minAcceptanceRateEnabled = false;
        $this->minRatingEnabled = false;
        $this->minActiveDaysEnabled = false;
    }

    public function isTicketBasedRaffle(): bool
    {
        return $this->type === ChallengeType::Raffle->value && $this->isTicketBased;
    }

    public function save(): void
    {
        $this->validate($this->allRules());

        $type = ChallengeType::from($this->type);
        $nature = PrizeNature::from($this->prizeNature);
        $mode = AwardMode::from($this->awardMode);
        $start = Carbon::parse($this->periodStart)->startOfDay();

        $name = trim($this->name) !== '' ? trim($this->name) : $this->suggestedName();

        // Un doublon exact (même nom, ou même type sur la même période) est
        // presque toujours une double soumission : on le refuse côté serveur.
        $duplicate = Challenge::query()
            ->where(fn ($query) => $query->where('name', $name)
                ->orWhere(fn ($inner) => $inner->where('type', $type)
                    ->whereDate('period_start', $start)))
            ->first();

        if ($duplicate !== null) {
            throw ValidationException::withMessages([
                'name' => __('backoffice.challenges.duplicate_exists', ['name' => $duplicate->name, 'reference' => $duplicate->reference]),
            ]);
        }

        $isDirection = auth('web')->user()?->hasRole('direction') ?? false;

        $challenge = Challenge::query()->create([
            'reference' => $this->nextReference(),
            'name' => $name,
            'type' => $type,
            'status' => $isDirection
                ? ($type === ChallengeType::Leaderboard ? ChallengeStatus::Active : ChallengeStatus::Scheduled)
                : ChallengeStatus::PendingApproval,
            'period_start' => $start,
            'period_end' => Carbon::parse($this->periodEnd)->endOfDay(),
            'week_iso' => $start->format('o-\WW'),
            'recurrence' => ChallengeRecurrence::from($this->recurrence),
            'min_orders_enabled' => $this->minOrdersEnabled,
            'min_orders' => $this->minOrdersEnabled ? $this->minOrders : null,
            'top_n_enabled' => $this->topNEnabled,
            'top_n' => $this->topNEnabled ? $this->topN : null,
            'min_acceptance_rate_enabled' => $this->minAcceptanceRateEnabled,
            'min_acceptance_rate' => $this->minAcceptanceRateEnabled ? $this->minAcceptanceRate : null,
            'min_rating_enabled' => $this->minRatingEnabled,
            'min_rating' => $this->minRatingEnabled ? (float) str_replace(',', '.', (string) $this->minRating) : null,
            'min_active_days_enabled' => $this->minActiveDaysEnabled,
            'min_active_days' => $this->minActiveDaysEnabled ? $this->minActiveDays : null,
            'prize_nature' => $nature,
            'reward_amount' => $nature === PrizeNature::Cash ? $this->rewardAmount : null,
            'prize_id' => $nature === PrizeNature::PhysicalItem ? $this->prizeId : null,
            'award_mode' => $mode,
            'winners_count' => $type === ChallengeType::Surprise ? null : ($mode === AwardMode::SingleWinner ? 1 : $this->winnersCount),
            'population_max' => $type === ChallengeType::Surprise ? $this->populationMax : null,
            'is_ticket_based' => $type === ChallengeType::Raffle ? $this->isTicketBased : false,
            'trips_per_ticket' => $type === ChallengeType::Raffle && $this->isTicketBased ? $this->minOrders : null,
            'eligibles_count' => $this->estimatedEligibles(),
            'created_by' => auth()->id(),
        ]);

        $this->open = false;

        $this->dispatch('challenge-created');
        $this->dispatch('toast', message: $isDirection
            ? __('backoffice.challenges.created_scheduled')
            : __('backoffice.challenges.created_pending'));

        $this->redirectRoute('bo.challenges.show', ['challenge' => $challenge], navigate: true);
    }

    /**
     * Estimation des conducteurs éligibles (source : prototype, `wizEstim`) :
     * une base de parc décroissante à chaque critère activé. Indicatif —
     * l'éligibilité réelle est recalculée au gel du pool.
     */
    public function estimatedEligibles(): int
    {
        $count = 1_284;

        if ($this->minOrdersEnabled) {
            $count = max(4, (int) round($count * exp(-((int) $this->minOrders) / 90)));
        }

        if ($this->minAcceptanceRateEnabled) {
            $count = (int) round($count * 0.82);
        }

        if ($this->minRatingEnabled) {
            $count = (int) round($count * 0.76);
        }

        if ($this->minActiveDaysEnabled) {
            $count = (int) round($count * 0.71);
        }

        return max(1, $count);
    }

    /**
     * Coût maximal : montant par gagnant × nombre de gagnants effectif,
     * plafonné par le nombre d'éligibles estimés.
     */
    public function maximumCost(): string
    {
        if ($this->prizeNature === PrizeNature::PhysicalItem->value) {
            $prize = $this->prizeId !== null ? Prize::find($this->prizeId) : null;

            return '1 × '.($prize->name ?? __('backoffice.challenges.prize_to_pick'));
        }

        $winners = min($this->effectiveWinnersCount(), $this->estimatedEligibles());

        return number_format(((int) $this->rewardAmount) * $winners, 0, ',', ' ').' FCFA';
    }

    public function effectiveWinnersCount(): int
    {
        if ($this->awardMode === AwardMode::SingleWinner->value) {
            return 1;
        }

        if ($this->type === ChallengeType::Surprise->value) {
            return (int) ($this->populationMax ?? 1);
        }

        return (int) ($this->winnersCount ?? 1);
    }

    /**
     * Nom suggéré en filigrane (source : prototype, `nomPeriode`).
     */
    public function suggestedName(): string
    {
        $week = Carbon::parse($this->periodStart)->isoWeek();

        return match (ChallengeType::from($this->type)) {
            ChallengeType::Leaderboard => 'Top '.($this->topN ?? 100).' — Semaine '.$week,
            ChallengeType::Raffle => 'Tombola Daba Guéhou — Semaine '.$week,
            ChallengeType::Surprise => 'Bonus surprise — Semaine '.$week,
        };
    }

    /**
     * Seule la duplication préremplit l'assistant : les modèles nommés ont été
     * retirés (l'assistant part d'un formulaire vierge).
     */
    private function applyTemplate(string $template): void
    {
        if (! str_starts_with($template, 'duplicate:')) {
            return;
        }

        $this->applyDuplicate(substr($template, strlen('duplicate:')));
    }

    /**
     * Duplication : mêmes réglages, période décalée d'une semaine.
     */
    private function applyDuplicate(string $challengeId): void
    {
        $source = Challenge::find($challengeId);

        if ($source === null) {
            return;
        }

        $this->selectType($source->type->value);
        $this->recurrence = $source->recurrence->value;
        $this->minOrdersEnabled = $source->min_orders_enabled;
        $this->minOrders = $source->min_orders;
        $this->topNEnabled = $source->top_n_enabled;
        $this->topN = $source->top_n;
        $this->minAcceptanceRateEnabled = $source->min_acceptance_rate_enabled;
        $this->minAcceptanceRate = $source->min_acceptance_rate;
        $this->minRatingEnabled = $source->min_rating_enabled;
        $this->minRating = $source->min_rating === null ? null : (string) $source->min_rating;
        $this->minActiveDaysEnabled = $source->min_active_days_enabled;
        $this->minActiveDays = $source->min_active_days;
        $this->prizeNature = $source->prize_nature->value;
        $this->rewardAmount = $source->reward_amount;
        $this->prizeId = $source->prize_id;
        $this->awardMode = $source->award_mode->value;
        $this->winnersCount = $source->winners_count;
        $this->populationMax = $source->population_max;
        $this->isTicketBased = $source->is_ticket_based;
        $this->tripsPerTicket = $source->trips_per_ticket;

        if ($source->is_ticket_based) {
            $this->minOrdersEnabled = true;
            $this->minOrders = $source->trips_per_ticket;
        }

        $this->applyTicketingConstraints();

        $this->periodStart = $source->period_start->copy()->addWeek()->toDateString();
        $this->periodEnd = $source->period_end->copy()->addWeek()->toDateString();
        $this->name = $this->shiftWeekInName($source->name);

        $this->step = 2;
    }

    /**
     * « … — Semaine 35 » → « … — Semaine 36 » (source : prototype, `nomDuplique`).
     */
    private function shiftWeekInName(string $name): string
    {
        $week = 'Semaine '.Carbon::parse($this->periodStart)->isoWeek();

        if (preg_match('/semaine\s+\d+/iu', $name) === 1) {
            return (string) preg_replace('/semaine\s+\d+/iu', $week, $name);
        }

        return "{$name} — {$week}";
    }

    private function nextReference(): string
    {
        $year = now()->year;
        $last = Challenge::query()
            ->where('reference', 'like', "CH-{$year}-%")
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last === null ? 1 : ((int) substr((string) $last, -3)) + 1;

        return sprintf('CH-%d-%03d', $year, $next);
    }

    private function resetWizard(): void
    {
        $this->reset([
            'step', 'name', 'type', 'recurrence',
            'minOrdersEnabled', 'minOrders', 'topNEnabled', 'topN',
            'minAcceptanceRateEnabled', 'minAcceptanceRate',
            'minRatingEnabled', 'minRating', 'minActiveDaysEnabled', 'minActiveDays',
            'prizeNature', 'rewardAmount', 'prizeId', 'awardMode',
            'winnersCount', 'populationMax', 'isTicketBased', 'tripsPerTicket',
        ]);

        $this->resetPeriod();
        $this->resetValidation();
    }

    private function resetPeriod(): void
    {
        $this->periodStart = now()->startOfWeek()->toDateString();
        $this->periodEnd = now()->endOfWeek()->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => array_merge(
                ['type' => 'required|in:leaderboard,raffle,surprise'],
                $this->isTicketBasedRaffle() ? ['minOrders' => 'required|integer|min:1'] : [],
            ),
            2 => $this->criteriaRules(),
            3 => [
                'name' => 'nullable|string|max:255',
                'periodStart' => 'required|date',
                'periodEnd' => 'required|date|after_or_equal:periodStart',
                'recurrence' => 'required|in:hebdo,mensuel,ponctuel',
            ],
            4 => $this->prizeRules(),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function criteriaRules(): array
    {
        // Tirage à tickets : le nombre de courses par ticket est le seul
        // critère, et il est obligatoire.
        if ($this->isTicketBasedRaffle()) {
            return ['minOrders' => 'required|integer|min:1'];
        }

        return [
            'minOrders' => $this->minOrdersEnabled ? 'required|integer|min:1' : 'nullable',
            'topN' => $this->topNEnabled ? 'required|integer|min:1' : 'nullable',
            'minAcceptanceRate' => $this->minAcceptanceRateEnabled ? 'required|integer|min:1|max:100' : 'nullable',
            'minRating' => $this->minRatingEnabled ? 'required|numeric|min:1|max:5' : 'nullable',
            'minActiveDays' => $this->minActiveDaysEnabled ? 'required|integer|min:1' : 'nullable',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prizeRules(): array
    {
        $rules = ['prizeNature' => 'required|in:cash,lot', 'awardMode' => 'required|in:collectif,unique'];

        if ($this->prizeNature === PrizeNature::Cash->value) {
            $rules['rewardAmount'] = 'required|integer|min:1';
        } else {
            $rules['prizeId'] = 'required|exists:prizes,id';
        }

        if ($this->type === ChallengeType::Surprise->value) {
            $rules['populationMax'] = 'required|integer|min:1';
        } elseif ($this->awardMode === AwardMode::Collective->value) {
            $rules['winnersCount'] = 'required|integer|min:1';
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function allRules(): array
    {
        return array_merge(
            $this->rulesForStep(1),
            $this->rulesForStep(2),
            $this->rulesForStep(3),
            $this->rulesForStep(4),
        );
    }

    /**
     * Libellés des onglets d'étape.
     *
     * @return list<string>
     */
    public function stepLabels(): array
    {
        return [
            __('backoffice.challenges.step_type'),
            __('backoffice.challenges.step_criteria'),
            __('backoffice.challenges.step_period'),
            __('backoffice.challenges.step_prize'),
        ];
    }

    public function stepTitle(): string
    {
        return [
            __('backoffice.challenges.step_type_title'),
            __('backoffice.challenges.step_criteria_title'),
            __('backoffice.challenges.step_period_title'),
            __('backoffice.challenges.step_prize_title'),
        ][$this->step - 1];
    }

    public function stepHint(): string
    {
        return [
            __('backoffice.challenges.step_type_hint'),
            $this->isTicketBasedRaffle()
                ? __('backoffice.challenges.step_criteria_hint_ticket')
                : __('backoffice.challenges.step_criteria_hint'),
            __('backoffice.challenges.step_period_hint'),
            __('backoffice.challenges.step_prize_hint'),
        ][$this->step - 1];
    }

    public function render(): View
    {
        return view('livewire.challenges.wizard', [
            'types' => ChallengeType::cases(),
            'recurrences' => ChallengeRecurrence::cases(),
            'natures' => PrizeNature::cases(),
            'modes' => AwardMode::cases(),
            'lots' => Prize::query()->orderBy('name')->get(),
        ]);
    }
}
