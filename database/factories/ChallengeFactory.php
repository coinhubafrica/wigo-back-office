<?php

namespace Database\Factories;

use App\Enums\AwardMode;
use App\Enums\ChallengeRecurrence;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Enums\PrizeNature;
use App\Models\Challenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Challenge>
 */
class ChallengeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = now()->startOfWeek();
        $periodEnd = now()->endOfWeek();

        return [
            'reference' => 'CH-'.now()->year.'-'.Str::upper(Str::random(4)),
            'name' => 'Top 100 hebdo',
            'type' => ChallengeType::Leaderboard,
            'status' => ChallengeStatus::Scheduled,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'week_iso' => $periodStart->format('o-\WW'),
            'recurrence' => ChallengeRecurrence::Weekly,
            'min_orders_enabled' => true,
            'min_orders' => 50,
            'top_n_enabled' => true,
            'top_n' => 100,
            'min_acceptance_rate_enabled' => true,
            'min_acceptance_rate' => 80,
            'prize_nature' => PrizeNature::Cash,
            'reward_amount' => 5_000,
            'award_mode' => AwardMode::Collective,
            'winners_count' => 100,
            'participants_count' => 1_284,
            'eligibles_count' => 737,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Modèle « Tombola hebdomadaire » : lot physique, gagnant unique, tickets.
     * Un tirage à tickets n'a qu'un critère — la tranche de courses qui donne
     * un ticket — donc `$tripsPerTicket` alimente aussi `min_orders`.
     */
    public function raffle(bool $ticketBased = true, int $tripsPerTicket = 50): static
    {
        return $this->state(fn (): array => [
            'name' => 'Tombola Daba Guéhou',
            'type' => ChallengeType::Raffle,
            'min_orders_enabled' => true,
            'min_orders' => $tripsPerTicket,
            'top_n_enabled' => false,
            'top_n' => null,
            'min_acceptance_rate_enabled' => false,
            'min_acceptance_rate' => null,
            'prize_nature' => PrizeNature::PhysicalItem,
            'reward_amount' => null,
            'award_mode' => AwardMode::SingleWinner,
            'winners_count' => 1,
            'is_ticket_based' => $ticketBased,
            'trips_per_ticket' => $ticketBased ? $tripsPerTicket : null,
        ]);
    }

    /**
     * Modèle « Bonus surprise ponctuel » : tirage aléatoire plafonné.
     */
    public function surprise(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Bonus surprise',
            'type' => ChallengeType::Surprise,
            'status' => ChallengeStatus::PendingApproval,
            'recurrence' => ChallengeRecurrence::OneOff,
            'min_orders' => 130,
            'top_n_enabled' => false,
            'top_n' => null,
            'min_acceptance_rate_enabled' => false,
            'min_acceptance_rate' => null,
            'min_active_days_enabled' => true,
            'min_active_days' => 6,
            'reward_amount' => 1_500,
            'award_mode' => AwardMode::Collective,
            'winners_count' => null,
            'population_max' => 3,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => ChallengeStatus::Active]);
    }

    public function drawPending(): static
    {
        return $this->state(fn (): array => ['status' => ChallengeStatus::DrawPending]);
    }

    public function rejected(string $reason = 'Budget non disponible ce mois'): static
    {
        return $this->state(fn (): array => [
            'status' => ChallengeStatus::Rejected,
            'rejection_reason' => $reason,
        ]);
    }
}
