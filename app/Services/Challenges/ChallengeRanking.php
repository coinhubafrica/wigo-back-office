<?php

namespace App\Services\Challenges;

use App\Enums\ChallengeType;
use App\Enums\YangoOrderStatus;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\Driver;
use App\Models\YangoOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Le classement d'un challenge, en base et rien qu'en base.
 *
 * Extrait de `Livewire\Challenges\Show` pour que la clôture automatique
 * (`ChallengeLifecycleService`) désigne les gagnants d'un classement avec la
 * même requête que l'écran : deux formulations du même rang finiraient par
 * diverger, et le rang est ce que le conducteur conteste.
 *
 * Attention au vocabulaire : « participants » ne désigne pas des inscrits.
 * Tout conducteur participe à tout challenge — il n'y a pas d'inscription.
 * `participants()` filtre ce qu'il y a à *afficher* (qui a roulé, qui détient
 * un ticket), jamais qui a le droit de gagner.
 */
class ChallengeRanking
{
    public function __construct(private readonly Challenge $challenge) {}

    /**
     * Conducteurs ayant terminé au moins une course sur la période — ou
     * porteurs d'un ticket pour une tombola —, avec leurs deux compteurs en
     * colonnes calculées. Sans ordre : `ranked()` le pose.
     *
     * Tout reste en base. L'ancienne version hydratait le parc entier, puis
     * filtrait et triait en PHP, quatre fois par rendu : la page tombait en
     * délai d'attente dès que le parc a grossi. Ici les sous-requêtes ne sont
     * évaluées que pour les lignes que `whereHas` retient, et s'appuient sur
     * `yango_orders (driver_id, status, completed_at)` et
     * `challenge_tickets (challenge_id, driver_id)`.
     *
     * @return Builder<Driver> colonnes ajoutées : `period_orders`, `period_tickets`
     */
    public function participants(): Builder
    {
        $period = [$this->challenge->period_start, $this->challenge->period_end];

        $completedOrders = YangoOrder::query()
            ->selectRaw('count(*)')
            ->whereColumn('yango_orders.driver_id', 'drivers.id')
            ->where('status', YangoOrderStatus::Complete)
            ->whereBetween('completed_at', $period);

        $tickets = ChallengeTicket::query()
            ->selectRaw('count(*)')
            ->whereColumn('challenge_tickets.driver_id', 'drivers.id')
            ->where('challenge_id', $this->challenge->id);

        return Driver::query()
            ->select(['drivers.id', 'drivers.first_name', 'drivers.last_name', 'drivers.yango_id'])
            ->selectSub($completedOrders, 'period_orders')
            ->selectSub($tickets, 'period_tickets')
            ->when(
                $this->challenge->type === ChallengeType::Raffle,
                fn (Builder $query) => $query->whereHas('challengeTickets', fn (Builder $ticket) => $ticket
                    ->where('challenge_id', $this->challenge->id)),
                fn (Builder $query) => $query->whereHas('yangoOrders', fn (Builder $order) => $order
                    ->where('status', YangoOrderStatus::Complete)
                    ->whereBetween('completed_at', $period)),
            );
    }

    /**
     * Le classement numéroté. `place` est calculé par fenêtre sur l'ensemble
     * des participants, **avant** tout filtre d'affichage : chercher un
     * conducteur montre son vrai rang, pas sa position parmi les résultats.
     *
     * `rank` est un mot réservé de MySQL, d'où `place`.
     */
    public function ranked(): QueryBuilder
    {
        $column = $this->challenge->type === ChallengeType::Raffle ? 'period_tickets' : 'period_orders';

        return DB::query()
            ->fromSub($this->participants(), 'ranking')
            ->select('ranking.*')
            ->selectRaw("row_number() over (order by {$column} desc, id) as place")
            ->orderBy('place');
    }
}
