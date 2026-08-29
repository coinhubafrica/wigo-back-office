<?php

namespace Database\Seeders;

use App\Enums\AwardMode;
use App\Enums\ChallengeRecurrence;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Enums\PrizeNature;
use App\Models\Challenge;
use App\Models\ChallengeWinner;
use App\Models\Driver;
use App\Models\Prize;
use App\Models\User;
use App\Services\Challenges\DrawService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Un challenge par branche d'écran distincte, pour couvrir le cycle de vie
 * complet sans devoir tout créer à la main dans l'interface. Les libellés et
 * les références reprennent ceux du prototype.
 *
 * Idempotent par référence : rejouable sans dupliquer les lignes.
 */
class ChallengeSeeder extends Seeder
{
    public function run(): void
    {
        $bonus = User::where('email', 'bonus@atconfortplus.ci')->first();

        if ($bonus === null) {
            $this->command->warn('Utilisateur bonus@atconfortplus.ci introuvable : exécutez UserSeeder avant ChallengeSeeder.');

            return;
        }

        $drivers = Driver::query()->get();

        if ($drivers->isEmpty()) {
            $this->command->warn('Aucun conducteur trouvé : exécutez DriverSeeder avant ChallengeSeeder.');

            return;
        }

        $lots = $this->prizes();

        // 1. Classement en cours, semaine courante.
        $this->challenge('CH-2026-041', [
            'name' => 'Top 100 — Semaine '.Carbon::now()->isoWeek(),
            'type' => ChallengeType::Leaderboard,
            'status' => ChallengeStatus::Active,
            'period_start' => Carbon::now()->startOfWeek(),
            'period_end' => Carbon::now()->endOfWeek(),
            'week_iso' => Carbon::now()->format('o-\WW'),
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
            'created_by' => $bonus->id,
        ]);

        // 2. Classement clôturé : les bonus restent à déposer sur Yango.
        $payoutPending = $this->challenge('CH-2026-040', [
            'name' => 'Top 100 — Semaine '.Carbon::now()->subWeek()->isoWeek(),
            'type' => ChallengeType::Leaderboard,
            'status' => ChallengeStatus::PayoutPending,
            'period_start' => Carbon::now()->subWeek()->startOfWeek(),
            'period_end' => Carbon::now()->subWeek()->endOfWeek(),
            'week_iso' => Carbon::now()->subWeek()->format('o-\WW'),
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
            'participants_count' => 1_251,
            'created_by' => $bonus->id,
        ]);

        $this->seedWinners($payoutPending, $drivers, $bonus);

        // 3. Tombola en cours : pool vivant, tickets minés au fil de l'eau
        // par YangoOrderSeeder.
        $this->challenge('CH-2026-039', [
            'name' => 'Tombola Daba Guéhou — Semaine '.Carbon::now()->isoWeek(),
            'type' => ChallengeType::Raffle,
            'status' => ChallengeStatus::Active,
            'period_start' => Carbon::now()->subDays(3),
            'period_end' => Carbon::now()->addDays(3),
            'week_iso' => Carbon::now()->format('o-\WW'),
            'recurrence' => ChallengeRecurrence::Weekly,
            'min_orders_enabled' => true,
            'min_orders' => 50,
            'prize_nature' => PrizeNature::PhysicalItem,
            'prize_id' => $lots['Réfrigérateur']->id,
            'award_mode' => AwardMode::SingleWinner,
            'winners_count' => 1,
            'is_ticket_based' => true,
            'trips_per_ticket' => 50,
            'participants_count' => 1_251,
            'created_by' => $bonus->id,
        ]);

        // 4. Tombola clôturée dont le pool sera gelé en fin de seed
        // (cf. freezeDrawPendingFixture) : le tirage reste à effectuer.
        $this->challenge('CH-2026-038', [
            'name' => 'Tombola Daba Guéhou — Semaine '.Carbon::now()->subWeek()->isoWeek(),
            'type' => ChallengeType::Raffle,
            'status' => ChallengeStatus::Active,
            'period_start' => Carbon::now()->subDays(9),
            'period_end' => Carbon::now()->subDays(3),
            'week_iso' => Carbon::now()->subWeek()->format('o-\WW'),
            'recurrence' => ChallengeRecurrence::Weekly,
            'min_orders_enabled' => true,
            'min_orders' => 50,
            'prize_nature' => PrizeNature::PhysicalItem,
            'prize_id' => $lots['Téléviseur 32"']->id,
            'award_mode' => AwardMode::SingleWinner,
            'winners_count' => 1,
            'is_ticket_based' => true,
            'trips_per_ticket' => 50,
            'participants_count' => 1_198,
            'created_by' => $bonus->id,
        ]);

        // 5. Bonus surprise en attente d'approbation Direction.
        $this->challenge('CH-2026-042', [
            'name' => 'Bonus surprise — Rentrée',
            'type' => ChallengeType::Surprise,
            'status' => ChallengeStatus::PendingApproval,
            'period_start' => Carbon::now()->subWeek()->startOfWeek(),
            'period_end' => Carbon::now()->subWeek()->endOfWeek(),
            'recurrence' => ChallengeRecurrence::OneOff,
            'min_orders_enabled' => true,
            'min_orders' => 130,
            'min_active_days_enabled' => true,
            'min_active_days' => 6,
            'prize_nature' => PrizeNature::Cash,
            'reward_amount' => 1_500,
            'award_mode' => AwardMode::Collective,
            'population_max' => 2,
            'participants_count' => 1_251,
            'created_by' => $bonus->id,
        ]);

        // 6. Bonus surprise terminé, tous les gagnants crédités.
        $completedSurprise = $this->challenge('CH-2026-036', [
            'name' => 'Bonus surprise — Août',
            'type' => ChallengeType::Surprise,
            'status' => ChallengeStatus::Completed,
            'period_start' => Carbon::now()->subWeeks(3)->startOfWeek(),
            'period_end' => Carbon::now()->subWeeks(3)->endOfWeek(),
            'recurrence' => ChallengeRecurrence::OneOff,
            'min_orders_enabled' => true,
            'min_orders' => 120,
            'prize_nature' => PrizeNature::Cash,
            'reward_amount' => 2_000,
            'award_mode' => AwardMode::Collective,
            'population_max' => 3,
            'participants_count' => 1_176,
            'created_by' => $bonus->id,
        ]);

        $this->seedWinners($completedSurprise, $drivers, $bonus, allCredited: true);

        // 7. Bonus surprise rejeté par la Direction.
        $this->challenge('CH-2026-035', [
            'name' => 'Bonus surprise — refusé',
            'type' => ChallengeType::Surprise,
            'status' => ChallengeStatus::Rejected,
            'period_start' => Carbon::now()->subWeeks(4)->startOfWeek(),
            'period_end' => Carbon::now()->subWeeks(4)->endOfWeek(),
            'recurrence' => ChallengeRecurrence::OneOff,
            'min_orders_enabled' => true,
            'min_orders' => 100,
            'prize_nature' => PrizeNature::Cash,
            'reward_amount' => 10_000,
            'award_mode' => AwardMode::Collective,
            'population_max' => 5,
            'rejection_reason' => 'Budget non disponible ce mois-ci',
            'created_by' => $bonus->id,
        ]);

        $this->command->table(
            ['Référence', 'Nom', 'Type', 'Statut'],
            [
                ['CH-2026-041', 'Top 100 — semaine courante', 'classement', 'en cours'],
                ['CH-2026-040', 'Top 100 — semaine passée', 'classement', 'bonus à déposer'],
                ['CH-2026-039', 'Tombola — semaine courante', 'tirage', 'en cours (pool vivant)'],
                ['CH-2026-038', 'Tombola — semaine passée', 'tirage', 'tirage à effectuer'],
                ['CH-2026-042', 'Bonus surprise — Rentrée', 'surprise', 'à valider — Direction'],
                ['CH-2026-036', 'Bonus surprise — Août', 'surprise', 'terminé'],
                ['CH-2026-035', 'Bonus surprise — refusé', 'surprise', 'rejeté'],
            ],
        );
    }

    /**
     * Gèle le pool de la tombola clôturée une fois que les courses de sa
     * période existent (YangoOrderSeeder doit avoir tourné en premier). Appelé
     * séparément par DatabaseSeeder, après YangoOrderSeeder.
     */
    public function freezeDrawPendingFixture(): void
    {
        $challenge = Challenge::where('reference', 'CH-2026-038')->first();

        if ($challenge === null || $challenge->status !== ChallengeStatus::Active || ! $challenge->tickets()->exists()) {
            return;
        }

        app(DrawService::class)->freezePool($challenge->fresh());
    }

    /**
     * Catalogue des lots physiques, idempotent par nom. Les visuels sont des
     * images de substitution générées pour le développement : elles ne servent
     * qu'à exercer la grille de cartes, à remplacer par les photos réelles.
     *
     * @return array<string, Prize>
     */
    private function prizes(): array
    {
        $catalogue = [
            'Téléviseur 32"' => 'televiseur',
            'Réfrigérateur' => 'refrigerateur',
            'Cuisinière' => 'cuisiniere',
            'Smartphone' => 'smartphone',
        ];

        $prizes = [];

        foreach ($catalogue as $name => $slug) {
            $prizes[$name] = Prize::query()->firstOrCreate(
                ['name' => $name],
                ['photo_url' => $this->prizePhoto($slug)],
            );
        }

        return $prizes;
    }

    /**
     * Copie l'image de substitution sur le disque applicatif et renvoie son
     * chemin relatif. Idempotent : la copie est ignorée si le fichier existe.
     */
    private function prizePhoto(string $slug): ?string
    {
        $source = database_path("seeders/fixtures/prizes/{$slug}.jpg");

        if (! is_file($source)) {
            return null;
        }

        $path = "prizes/{$slug}.jpg";

        if (! Storage::exists($path)) {
            Storage::put($path, (string) file_get_contents($source));
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function challenge(string $reference, array $attributes): Challenge
    {
        $challenge = Challenge::where('reference', $reference)->first();

        if ($challenge === null) {
            return Challenge::query()->create([...$attributes, 'reference' => $reference]);
        }

        $challenge->forceFill($attributes)->save();

        return $challenge;
    }

    /**
     * @param  Collection<int, Driver>  $drivers
     */
    private function seedWinners(Challenge $challenge, Collection $drivers, User $bonus, bool $allCredited = false): void
    {
        if ($challenge->winners()->exists()) {
            return;
        }

        $winnerDrivers = $drivers->take(min(3, $drivers->count()));

        foreach ($winnerDrivers as $rank => $driver) {
            $credited = $allCredited || $rank === 0;

            ChallengeWinner::query()->create([
                'challenge_id' => $challenge->id,
                'driver_id' => $driver->id,
                'rank' => $challenge->type === ChallengeType::Leaderboard ? $rank + 1 : null,
                'amount' => $challenge->reward_amount,
                'credited' => $credited,
                'credited_by' => $credited ? $bonus->id : null,
                'credited_at' => $credited ? now() : null,
            ]);
        }
    }
}
