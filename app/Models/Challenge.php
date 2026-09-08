<?php

namespace App\Models;

use App\Enums\AwardMode;
use App\Enums\ChallengeRecurrence;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Enums\PrizeNature;
use App\Services\Challenges\ParticipantCounter;
use Carbon\CarbonImmutable;
use Database\Factories\ChallengeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $reference
 * @property string $name
 * @property ChallengeType $type
 * @property ChallengeStatus $status
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string|null $week_iso
 * @property ChallengeRecurrence $recurrence
 * @property bool $min_orders_enabled
 * @property int|null $min_orders
 * @property bool $top_n_enabled
 * @property int|null $top_n
 * @property bool $min_acceptance_rate_enabled
 * @property int|null $min_acceptance_rate
 * @property bool $min_rating_enabled
 * @property float|null $min_rating
 * @property bool $min_active_days_enabled
 * @property int|null $min_active_days
 * @property PrizeNature $prize_nature
 * @property int|null $reward_amount
 * @property string|null $prize_id
 * @property AwardMode $award_mode
 * @property int|null $winners_count
 * @property int|null $population_max
 * @property int|null $eligibles_count
 * @property bool $is_ticket_based
 * @property int|null $trips_per_ticket
 * @property string|null $draw_seed
 * @property string|null $draw_pool_hash
 * @property CarbonImmutable|null $drawn_at
 * @property string|null $rejection_reason
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string $created_by
 * @property-read Prize|null $prize
 * @property-read User|null $approvedBy
 * @property-read User $createdBy
 * @property-read Collection<int, ChallengeTicket> $tickets
 * @property-read Collection<int, ChallengeWinner> $winners
 */
class Challenge extends Model
{
    /** @use HasFactory<ChallengeFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ChallengeType::class,
            'status' => ChallengeStatus::class,
            'recurrence' => ChallengeRecurrence::class,
            'prize_nature' => PrizeNature::class,
            'award_mode' => AwardMode::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'min_orders_enabled' => 'boolean',
            'top_n_enabled' => 'boolean',
            'min_acceptance_rate_enabled' => 'boolean',
            'min_rating_enabled' => 'boolean',
            'min_active_days_enabled' => 'boolean',
            'min_rating' => 'decimal:1',
            'is_ticket_based' => 'boolean',
            'rules_document_size' => 'integer',
            'rules_document_uploaded_at' => 'datetime',
            'drawn_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Prize, $this>
     */
    public function prize(): BelongsTo
    {
        return $this->belongsTo(Prize::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ChallengeTicket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(ChallengeTicket::class);
    }

    /**
     * @return HasMany<ChallengeWinner, $this>
     */
    public function winners(): HasMany
    {
        return $this->hasMany(ChallengeWinner::class);
    }

    /**
     * Marque une liste comme devant porter son compte de participants.
     *
     * `challenges.participants_count` n'a jamais été renseigné hors du
     * seeder : la colonne restait nulle en production, et l'écran affichait
     * « 0 participant » sur un challenge que tout le parc courait. Le compte
     * est donc dérivé des courses — un conducteur participe dès qu'il a
     * terminé une course sur la période, puisqu'il n'y a pas d'inscription à
     * un challenge (cf. `.ai/rules/challenges.md`).
     *
     * Le compte **ne se pose plus en sous-requête** : corrélée aux bornes de
     * `challenges`, elle rendait l'index `(status, completed_at, driver_id)`
     * inutilisable et relisait tout l'historique par ligne affichée
     * (cf. `ParticipantCounter`). La liste hydrate donc l'attribut après
     * pagination, sur les seules lignes visibles.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithParticipantsCount(Builder $query): Builder
    {
        return $query->afterQuery(function (Collection $challenges): Collection {
            $counts = app(ParticipantCounter::class)->countForPeriods($challenges);

            return $challenges->each(fn (self $challenge) => $challenge->setAttribute(
                'participants',
                $counts[$challenge->id] ?? 0,
            ));
        });
    }

    /**
     * Nombre de participants : les conducteurs ayant terminé au moins une
     * course sur la période.
     *
     * Lit l'attribut posé par `withParticipantsCount()` s'il est là — une
     * liste paginée le pose une fois pour toutes —, et compte à la demande
     * sinon.
     */
    public function participantsCount(): int
    {
        if ($this->hasAttribute('participants')) {
            return (int) $this->getAttribute('participants');
        }

        return app(ParticipantCounter::class)->countForPeriod($this->period_start, $this->period_end);
    }

    /**
     * Un règlement est joint à ce challenge.
     */
    public function hasRulesDocument(): bool
    {
        return $this->rules_document_path !== null;
    }

    /**
     * Taille lisible du règlement : « 1,2 Mo », pas « 1258291 ».
     */
    public function rulesDocumentHumanSize(): string
    {
        $bytes = (int) $this->rules_document_size;

        return match (true) {
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 1, ',', ' ').' Mo',
            $bytes >= 1_024 => number_format($bytes / 1_024, 0, ',', ' ').' Ko',
            default => $bytes.' o',
        };
    }

    public function isTicketBasedRaffle(): bool
    {
        return $this->type === ChallengeType::Raffle && $this->is_ticket_based;
    }

    /**
     * Critères actifs, résumés comme dans le prototype (`critLibelle`) :
     * « ≥ 50 · Top 100 · ≥ 80 % ».
     *
     * @return list<array{label: string, value: string}>
     */
    public function activeCriteria(): array
    {
        if ($this->isTicketBasedRaffle()) {
            return [[
                'label' => 'Courses par ticket',
                'value' => "1 / {$this->trips_per_ticket}",
            ]];
        }

        $criteria = [];

        if ($this->min_orders_enabled) {
            $criteria[] = ['label' => 'Courses réalisées sur la période', 'value' => "≥ {$this->min_orders}"];
        }

        if ($this->top_n_enabled) {
            $criteria[] = ['label' => 'Classement par nombre de courses', 'value' => "Top {$this->top_n}"];
        }

        if ($this->min_acceptance_rate_enabled) {
            $criteria[] = ['label' => "Taux d'acceptation", 'value' => "≥ {$this->min_acceptance_rate} %"];
        }

        if ($this->min_rating_enabled) {
            $rating = number_format((float) $this->min_rating, 1, ',', '');
            $criteria[] = ['label' => 'Note moyenne conducteur', 'value' => "≥ {$rating} / 5"];
        }

        if ($this->min_active_days_enabled) {
            $criteria[] = ['label' => 'Jours actifs consécutifs', 'value' => "≥ {$this->min_active_days} jours"];
        }

        return $criteria;
    }

    public function criteriaSummary(): string
    {
        // Un tirage à tickets n'a qu'un critère : la tranche de courses qui
        // donne un ticket, et qui sert aussi de seuil d'entrée.
        if ($this->isTicketBasedRaffle()) {
            return "1 ticket / {$this->trips_per_ticket} courses";
        }

        $values = array_column($this->activeCriteria(), 'value');

        return $values === [] ? 'Aucun critère' : implode(' · ', $values);
    }

    /**
     * Libellé court du prix affiché dans la liste.
     */
    public function prizeLabel(): string
    {
        return $this->prize_nature === PrizeNature::PhysicalItem
            ? (string) $this->prize?->name
            : number_format((int) $this->reward_amount, 0, ',', ' ').' FCFA';
    }

    /**
     * Sous-libellé du prix : canal de remise et nombre de gagnants.
     */
    public function prizeSubLabel(): string
    {
        if ($this->prize_nature === PrizeNature::PhysicalItem) {
            return 'Lot physique · 1 gagnant';
        }

        if ($this->award_mode === AwardMode::SingleWinner) {
            return 'Transfert Yango · 1 gagnant';
        }

        return 'Transfert Yango · '.$this->effectiveWinnersCount().' gagnants';
    }

    /**
     * Nombre de gagnants effectif, tous types confondus.
     */
    public function effectiveWinnersCount(): int
    {
        if ($this->award_mode === AwardMode::SingleWinner) {
            return 1;
        }

        if ($this->type === ChallengeType::Surprise) {
            return (int) ($this->population_max ?? 1);
        }

        return (int) ($this->winners_count ?? 1);
    }
}
