<?php

namespace App\Livewire\Challenges;

use App\Enums\AuditAction;
use App\Enums\AwardMode;
use App\Enums\BackOfficeModule;
use App\Enums\ChallengeRecurrence;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Enums\PrizeNature;
use App\Jobs\SyncYangoOrdersJob;
use App\Livewire\Concerns\InteractsWithCurrentUser;
use App\Models\AuditLog;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\ChallengeWinner;
use App\Models\Driver;
use App\Services\Challenges\ChallengeLifecycleService;
use App\Services\Challenges\ChallengeRanking;
use App\Services\Challenges\DrawService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Détail d'un challenge. L'écran suit le statut : la période affiche sa
 * progression puis se fige, le tirage n'est exécutable qu'une fois le pool
 * gelé et la graine publiée, et les gratifications se pointent une par une.
 * Toute la logique d'aléatoire reste dans `DrawService`.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Challenges])]
class Show extends Component
{
    use InteractsWithCurrentUser, WithFileUploads;

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

    /**
     * Action destructrice en attente de confirmation (`close_period` ou
     * `credit_all`). Une modale plutôt que `wire:confirm` : le dialogue natif
     * bloque l'automatisation navigateur, comme constaté sur les recharges.
     */
    public ?string $pendingAction = null;

    /**
     * Règlement à joindre. PDF attendu, images acceptées : un agent n'a
     * parfois qu'une photo de la feuille imprimée.
     */
    #[Validate('required|file|mimes:pdf,jpg,jpeg,png,webp|max:5120')]
    public mixed $rulesDocument = null;

    public bool $confirmingRulesRemoval = false;

    /**
     * Compteurs résolus une fois par rendu : la vue relit les éligibles et le
     * nombre de tickets à plusieurs endroits (barre de progression, résumé de
     * la liste, vivier gelé). Propriétés privées, donc jamais sérialisées —
     * chaque requête Livewire repart de zéro, et les gestes qui écrivent
     * (`closePeriod`) ne passent pas par elles avant d'avoir écrit.
     */
    private ?int $eligibleCount = null;

    private ?int $ticketCount = null;

    private ?ChallengeRanking $ranking = null;

    /**
     * Lignes affichées de la liste et de l'instantané du vivier.
     */
    private const LIST_ROWS = 25;

    private const POOL_ROWS = 8;

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

        // `approved_by`/`approved_at` ne gardent que la *dernière* valeur : un
        // rejet suivi d'une approbation serait sinon perdu. C'est le contrôle
        // exercé sur le rôle Bonus, il doit se relire.
        AuditLog::record(
            action: AuditAction::ChallengeApproved->value,
            summary: "{$this->actor()->fullName()} a approuvé le challenge « {$this->challenge->name} ».",
            subject: $this->challenge,
            by: $this->actor(),
            context: ['status_after' => $this->challenge->status->value],
        );

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

        // Même décision que l'approbation, signe opposé ; `rejection_reason`
        // est écrasable, le motif doit survivre ici.
        AuditLog::record(
            action: AuditAction::ChallengeRejected->value,
            summary: "{$this->actor()->fullName()} a rejeté le challenge « {$this->challenge->name} ».",
            subject: $this->challenge,
            by: $this->actor(),
            context: ['reason' => $this->rejectionReason],
        );

        $this->showRejectForm = false;
        $this->rejectionReason = '';

        $this->dispatch('toast', message: __('backoffice.challenges.rejected'));
    }

    /**
     * Clôture manuelle : gèle le pool et, pour un classement, passe
     * directement au dépôt des bonus puisqu'il n'y a pas de tirage.
     */
    public function confirmAction(string $action): void
    {
        $this->pendingAction = $action;
    }

    public function cancelAction(): void
    {
        $this->pendingAction = null;
    }

    public function closePeriod(): void
    {
        Gate::authorize('closeChallengePeriod');

        $this->pendingAction = null;

        // Gel, journal et attribution vivent dans le service : le
        // planificateur clôt à l'échéance par le même chemin, acteur nul.
        app(ChallengeLifecycleService::class)->close($this->challenge, $this->actor());

        $this->challenge->refresh();

        $this->dispatch('toast', message: __('backoffice.challenges.period_closed'));
    }

    /**
     * Republie la graine du tirage.
     *
     * Le geste le plus sensible du module : il change le hasard alors que le
     * vivier est déjà gelé. Droit à part et journalisé — la loyauté du tirage
     * doit pouvoir se démontrer après coup.
     */
    public function regenerateSeed(): void
    {
        Gate::authorize('regenerateChallengeSeed');

        app(DrawService::class)->publishSeed($this->challenge);
        $this->challenge->refresh();

        AuditLog::record(
            action: AuditAction::ChallengeSeedRegenerated->value,
            summary: "{$this->actor()->fullName()} a republié la graine du tirage « {$this->challenge->name} ».",
            subject: $this->challenge,
            by: $this->actor(),
            context: ['seed' => $this->challenge->draw_seed],
        );

        $this->dispatch('toast', message: __('backoffice.challenges.seed_published'));
    }

    public function executeDraw(): void
    {
        Gate::authorize('drawChallenge');

        app(DrawService::class)->draw($this->challenge);
        $this->challenge->refresh();

        AuditLog::record(
            action: AuditAction::ChallengeDrawn->value,
            summary: "{$this->actor()->fullName()} a exécuté le tirage « {$this->challenge->name} ».",
            subject: $this->challenge,
            by: $this->actor(),
            context: [
                'seed' => $this->challenge->draw_seed,
                'winners' => $this->challenge->winners()->count(),
            ],
        );

        $this->dispatch('toast', message: __('backoffice.challenges.drawn'));
    }

    public function markCredited(string $winnerId): void
    {
        Gate::authorize('creditChallengePrize');

        $winner = $this->challenge->winners()->findOrFail($winnerId);

        if ($winner->credited) {
            return;
        }

        $winner->update([
            'credited' => true,
            'credited_by' => auth()->id(),
            'credited_at' => now(),
        ]);

        AuditLog::record(
            action: AuditAction::ChallengePrizeCredited->value,
            summary: "{$this->actor()->fullName()} a marqué un lot crédité sur « {$this->challenge->name} ».",
            subject: $this->challenge,
            by: $this->actor(),
            context: ['winner' => $winnerId],
        );

        $this->completeIfFullyCredited();

        $this->dispatch('toast', message: __('backoffice.challenges.winner_credited'));
    }

    /**
     * Dépôt en lot : marque tous les gagnants restants comme crédités.
     */
    public function creditAll(): void
    {
        Gate::authorize('creditChallengePrize');

        $this->pendingAction = null;

        $credited = $this->challenge->winners()->where('credited', false)->count();

        $this->challenge->winners()->where('credited', false)->update([
            'credited' => true,
            'credited_by' => auth()->id(),
            'credited_at' => now(),
        ]);

        AuditLog::record(
            action: AuditAction::ChallengePrizeCredited->value,
            summary: "{$this->actor()->fullName()} a marqué tous les lots crédités sur « {$this->challenge->name} ».",
            subject: $this->challenge,
            by: $this->actor(),
            context: ['winners' => $credited],
        );

        $this->completeIfFullyCredited();

        $this->dispatch('toast', message: __('backoffice.challenges.all_credited'));
    }

    /**
     * Clé de duplication consommée par l'assistant. Le bouton émet
     * l'évènement côté navigateur (cf. la vue) : un dispatch serveur serait
     * rejoué après une navigation `wire:navigate` et rouvrirait la modale.
     */
    /**
     * Joint le règlement au challenge, en remplaçant celui déjà en place.
     *
     * Le fichier va sur le disque privé : un règlement n'est pas secret, mais
     * il n'a pas à être énumérable par son chemin — le contrat mobile le sert
     * par URL signée, comme les autres pièces.
     */
    public function uploadRulesDocument(): void
    {
        Gate::authorize('manageChallengeRules');

        $this->validateOnly('rulesDocument');

        $previousDisk = $this->challenge->rules_document_disk;
        $previousPath = $this->challenge->rules_document_path;
        $replaced = $this->challenge->hasRulesDocument();

        $this->challenge->update([
            'rules_document_disk' => 'local',
            'rules_document_path' => $this->rulesDocument->store(
                "challenge-rules/{$this->challenge->getKey()}",
                'local',
            ),
            'rules_document_name' => $this->rulesDocument->getClientOriginalName(),
            'rules_document_mime' => $this->rulesDocument->getMimeType() ?? 'application/octet-stream',
            'rules_document_size' => $this->rulesDocument->getSize(),
            'rules_document_uploaded_at' => now(),
        ]);

        // Le fichier remplacé n'est plus référencé par personne : le garder ne
        // servirait qu'à encombrer le disque.
        if ($previousPath !== null) {
            Storage::disk($previousDisk ?? 'local')->delete($previousPath);
        }

        /*
        | Journalisé : les conducteurs se fondent sur ce document pour savoir ce
        | qui leur est promis, et un remplacement change cette promesse en
        | cours de challenge. La ligne dit lequel des deux gestes a eu lieu.
        */
        AuditLog::record(
            action: AuditAction::ChallengeRulesAttached->value,
            summary: $replaced
                ? "{$this->actor()->fullName()} a remplacé le règlement du challenge {$this->challenge->reference}."
                : "{$this->actor()->fullName()} a joint le règlement du challenge {$this->challenge->reference}.",
            subject: $this->challenge,
            by: $this->actor(),
            context: [
                'reference' => $this->challenge->reference,
                'file_name' => $this->challenge->rules_document_name,
                'replaced' => $replaced,
            ],
        );

        $this->reset('rulesDocument');
        $this->resetValidation();
        $this->dispatch('toast', message: __('backoffice.challenges.rules_attached'));
    }

    public function confirmRulesRemoval(): void
    {
        Gate::authorize('manageChallengeRules');

        $this->confirmingRulesRemoval = true;
    }

    public function cancelRulesRemoval(): void
    {
        $this->confirmingRulesRemoval = false;
    }

    /**
     * Retire le règlement : le lien disparaît du contrat mobile et le fichier
     * du disque.
     */
    public function removeRulesDocument(): void
    {
        Gate::authorize('manageChallengeRules');

        if (! $this->challenge->hasRulesDocument()) {
            $this->confirmingRulesRemoval = false;

            return;
        }

        $name = $this->challenge->rules_document_name;

        // Journalisé avant la suppression : après, il ne reste rien à citer.
        AuditLog::record(
            action: AuditAction::ChallengeRulesRemoved->value,
            summary: "{$this->actor()->fullName()} a retiré le règlement du challenge {$this->challenge->reference}.",
            subject: $this->challenge,
            by: $this->actor(),
            context: ['reference' => $this->challenge->reference, 'file_name' => $name],
        );

        Storage::disk($this->challenge->rules_document_disk ?? 'local')
            ->delete((string) $this->challenge->rules_document_path);

        $this->challenge->update([
            'rules_document_disk' => null,
            'rules_document_path' => null,
            'rules_document_name' => null,
            'rules_document_mime' => null,
            'rules_document_size' => null,
            'rules_document_uploaded_at' => null,
        ]);

        $this->confirmingRulesRemoval = false;
        $this->dispatch('toast', message: __('backoffice.challenges.rules_removed'));
    }

    /**
     * Redemande à Yango les courses de la période, puis recompte les tickets.
     *
     * Une passe parc **par journée**, et non une passe par conducteur : tout
     * le parc participe à un challenge, et le filtre `driver_profile.id` de
     * Yango ne prend qu'un identifiant à la fois — un job par participant
     * coûterait une boucle de curseur par participant, et se ferait refuser
     * bien avant la fin. Les jobs sont uniques par journée, donc un second
     * clic ne double rien.
     *
     * Non journalisé : la passe rejoue des données Yango, ne touche à aucun
     * argent et se relance sans conséquence. Ce qu'elle produit — les tickets
     * — se lit dans le vivier.
     */
    public function resyncOrders(): void
    {
        Gate::authorize('resyncChallengeOrders');

        $today = Carbon::today();
        $day = $this->challenge->period_start->copy()->startOfDay();
        $last = $this->challenge->period_end->copy()->startOfDay()->min($today);

        $queued = 0;

        while ($day->lessThanOrEqualTo($last)) {
            SyncYangoOrdersJob::dispatch($day->toDateString());

            $day = $day->addDay();
            $queued++;
        }

        $this->dispatch('toast', message: trans_choice('backoffice.challenges.resync_queued', $queued, ['count' => $queued]));
    }

    public function duplicateTemplateKey(): string
    {
        return 'duplicate:'.$this->challenge->id;
    }

    private function completeIfFullyCredited(): void
    {
        if (! $this->challenge->winners()->where('credited', false)->exists()) {
            $this->challenge->update(['status' => ChallengeStatus::Completed]);
            $this->challenge->refresh();
        }
    }

    /**
     * Le classement, en base. Mémoïsé par rendu : la vue le relit pour le
     * nombre de participants, la liste et son résumé.
     */
    private function ranking(): ChallengeRanking
    {
        return $this->ranking ??= new ChallengeRanking($this->challenge);
    }

    /**
     * Tickets émis sur le challenge, comptés une fois par rendu.
     */
    private function ticketCount(): int
    {
        return $this->ticketCount ??= $this->challenge->tickets()->count();
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
                'value' => number_format($this->challenge->participantsCount(), 0, ',', ' '),
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
                'value' => number_format($this->ticketCount(), 0, ',', ' '),
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

    /**
     * Nombre de participants, compté en base et une fois par rendu.
     */
    public function eligibleCount(): int
    {
        return $this->eligibleCount ??= $this->ranking()->participants()->count();
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
                'tickets' => number_format($this->ticketCount(), 0, ',', ' '),
            ]),
            ChallengeType::Surprise => __('backoffice.challenges.list_summary_surprise', [
                'drivers' => number_format($count, 0, ',', ' '),
            ]),
        };
    }

    /**
     * Lignes de la liste des participants : recherche, filtre et limite sont
     * appliqués en base **sur le classement déjà numéroté**, pour que le rang
     * affiché reste celui de l'ensemble.
     *
     * @return list<array<string, mixed>>
     */
    public function listRows(): array
    {
        $winnerDriverIds = $this->challenge->winners()->pluck('driver_id')->all();
        $isLeaderboard = $this->challenge->type === ChallengeType::Leaderboard;
        $places = $isLeaderboard ? (int) ($this->challenge->winners_count ?? 0) : 0;

        // Gagnant : désigné par un tirage, ou — pour un classement — dans les
        // N premières places. La même clause sert au filtre et à son inverse.
        $winning = fn (QueryBuilder $query): QueryBuilder => $query
            ->whereIn('id', $winnerDriverIds)
            ->when($places > 0, fn (QueryBuilder $query) => $query->orWhere('place', '<=', $places));

        $rows = DB::query()
            ->fromSub($this->ranking()->ranked(), 'ranked')
            ->when($this->listSearch !== '', function (QueryBuilder $query): void {
                $term = "%{$this->listSearch}%";
                $query->where(fn (QueryBuilder $query) => $query
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('yango_id', 'like', $term));
            })
            ->when($this->listFilter === 'gagnants', fn (QueryBuilder $query) => $query->where($winning))
            ->when($this->listFilter === 'hors', fn (QueryBuilder $query) => $query->whereNot($winning))
            ->orderBy('place')
            ->limit(self::LIST_ROWS)
            ->get();

        return $rows
            ->map(function (object $row) use ($winnerDriverIds, $places, $isLeaderboard): array {
                $place = (int) $row->place;
                $isWinner = in_array($row->id, $winnerDriverIds, true) || ($places > 0 && $place <= $places);

                return [
                    'rank' => $place,
                    'name' => trim("{$row->first_name} {$row->last_name}"),
                    'account' => $row->yango_id ?? '—',
                    'orders' => (int) $row->period_orders,
                    'tickets' => (int) $row->period_tickets,
                    'isWinner' => $isWinner,
                    'label' => $isWinner
                        ? __('backoffice.challenges.winner_badge')
                        : ($isLeaderboard
                            ? __('backoffice.challenges.outside_top', ['top' => $places])
                            : __('backoffice.challenges.eligible_badge')),
                ];
            })
            ->all();
    }

    /**
     * Instantané figé du pool, tel qu'il sera rejoué depuis la graine : un
     * porteur par ligne, gagnants en tête, huit lignes au plus.
     *
     * Agrégé en base : le vivier d'une tombola compte des milliers de tickets,
     * les charger tous avec leur conducteur pour en montrer huit ne tient pas.
     *
     * @return list<array{name: string, tickets: int, range: string, isWinner: bool}>
     */
    public function frozenPoolRows(): array
    {
        $winnerNumbers = $this->challenge->winners()->pluck('winning_range_number')->filter()->values()->all();

        $holders = ChallengeTicket::query()
            ->where('challenge_id', $this->challenge->id)
            ->whereNotNull('range_number')
            ->groupBy('driver_id')
            ->selectRaw('driver_id, count(*) as tickets, min(range_number) as range_min, max(range_number) as range_max')
            ->when(
                $winnerNumbers !== [],
                fn (Builder $query) => $query->selectRaw(
                    'max(case when range_number in ('.implode(', ', array_fill(0, count($winnerNumbers), '?')).') then 1 else 0 end) as is_winner',
                    $winnerNumbers,
                ),
                fn (Builder $query) => $query->selectRaw('0 as is_winner'),
            )
            ->orderByDesc('is_winner')
            ->orderBy('range_min')
            ->limit(self::POOL_ROWS)
            ->get();

        $drivers = Driver::query()
            ->whereKey($holders->pluck('driver_id'))
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        return $holders
            ->map(fn (ChallengeTicket $holder): array => [
                'name' => $drivers->get($holder->driver_id)?->fullName() ?? '—',
                'tickets' => (int) $holder->tickets,
                'range' => number_format((int) $holder->range_min, 0, ',', ' ').' – '.number_format((int) $holder->range_max, 0, ',', ' '),
                'isWinner' => (bool) $holder->is_winner,
            ])
            ->all();
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

    /**
     * Le pilotage du cycle de vie est-il ouvert à cet agent ?
     *
     * Lu par la vue pour montrer ou masquer les boutons. Résolu par
     * permissions et non par nom de rôle : les rôles s'administrent à l'écran,
     * et un `hasAnyRole` ici masquait des boutons que les portails, eux,
     * autorisaient.
     */
    public function canManageBonus(): bool
    {
        return Gate::any([
            'closeChallengePeriod',
            'drawChallenge',
            'creditChallengePrize',
        ]);
    }

    public function render(): View
    {
        // `load` et non `loadMissing` : après un tirage ou un crédit, la
        // relation déjà chargée serait périmée.
        $this->challenge->load(['prize', 'createdBy', 'winners.driver', 'winners.prize']);

        // Les totaux se lisent sur la relation qu'on vient de charger.
        $winners = $this->winnerRows();
        $totalWinners = $this->challenge->winners->count();
        $credited = $this->challenge->winners->where('credited', true)->count();

        return view('livewire.challenges.show', [
            'challenge' => $this->challenge,
            'progress' => $this->periodProgress(),
            'stats' => $this->progressStats(),
            'definition' => $this->definitionCells(),
            'winners' => $winners,
            'totalWinners' => $totalWinners,
            'creditedCount' => $credited,
            'canManage' => $this->canManageBonus(),
            'canManageRules' => Gate::allows('manageChallengeRules'),
        ]);
    }
}
