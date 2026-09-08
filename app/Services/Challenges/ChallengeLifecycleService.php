<?php

namespace App\Services\Challenges;

use App\Enums\AuditAction;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Models\AuditLog;
use App\Models\Challenge;
use App\Models\ChallengeWinner;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Le cycle de vie d'un challenge, du démarrage à la clôture.
 *
 * Deux appelants et un seul chemin : l'écran de détail (un agent clique
 * « Clôturer la période ») et le planificateur (`challenges:advance`, à
 * l'échéance). L'acteur les distingue — `null` vaut « le planificateur » et
 * l'écran d'audit l'affiche « Système » —, mais les étapes sont les mêmes :
 * geler le vivier, journaliser, puis désigner les gagnants d'un classement ou
 * publier la graine d'un tirage.
 *
 * Rien n'ouvrait `Scheduled` avant : le formulaire et l'approbation posaient
 * `Scheduled` pour une tombola, et seuls les statuts `Active`/`DrawPending`
 * émettent des tickets et remontent au mobile. Une tombola n'a donc jamais
 * démarré en production.
 */
class ChallengeLifecycleService
{
    /**
     * Délai de grâce avant la clôture automatique.
     *
     * `yango:sync-orders` ne fait que *mettre en file* à l'heure ronde, et un
     * job peut se rejouer 60, 300 puis 600 secondes plus tard : geler le
     * vivier à minuit passé de quelques minutes laisserait dehors les courses
     * de la dernière heure. Deux heures laissent deux passes horaires
     * repasser sur la dernière journée avant que le pool ne se ferme.
     */
    public const CLOSE_GRACE_HOURS = 2;

    public function __construct(
        private readonly DrawService $draw,
        private readonly ChallengeTicketMinter $minter,
    ) {}

    /**
     * Démarre les challenges programmés dont la période a commencé.
     *
     * `PendingApproval` n'est jamais touché : une approbation est un geste
     * humain, et l'échéance ne l'accorde pas.
     *
     * @return int nombre de challenges démarrés
     */
    public function activateDue(CarbonInterface $now): int
    {
        $due = Challenge::query()
            ->where('status', ChallengeStatus::Scheduled)
            ->where('period_start', '<=', $now)
            ->get();

        foreach ($due as $challenge) {
            $challenge->update(['status' => ChallengeStatus::Active]);

            AuditLog::record(
                action: AuditAction::ChallengeActivated->value,
                summary: "Le planificateur a démarré le challenge « {$challenge->name} » à l'ouverture de sa période.",
                subject: $challenge,
                context: ['status_after' => ChallengeStatus::Active->value],
            );

            // Rattrapage : un challenge créé après le début de sa période a
            // des courses déjà en base et personne ne les avait comptées.
            $this->minter->mintFor($challenge);
        }

        return $due->count();
    }

    /**
     * Clôt les challenges en cours dont la période est échue depuis assez
     * longtemps pour que la dernière journée soit synchronisée.
     *
     * @return int nombre de challenges clos
     */
    public function closeDue(CarbonInterface $now): int
    {
        $due = Challenge::query()
            ->where('status', ChallengeStatus::Active)
            ->where('period_end', '<', $now->copy()->subHours(self::CLOSE_GRACE_HOURS))
            ->get();

        foreach ($due as $challenge) {
            // Dernière passe avant le gel : après, le vivier est haché et
            // plus aucun ticket n'entre.
            $this->minter->mintFor($challenge);

            $this->close($challenge);
        }

        return $due->count();
    }

    /**
     * Gèle le vivier, journalise, puis attribue.
     *
     * `$actor` à `null` signifie le planificateur : `AuditLog::record` écrit
     * alors `user_id` nul, que l'écran d'audit rend « Système ».
     */
    public function close(Challenge $challenge, ?User $actor = null): void
    {
        $this->draw->freezePool($challenge);
        $challenge->refresh();

        /*
        | Gèle le vivier : plus personne n'entre après. C'est l'ancre à
        | laquelle `challenge.seed_regenerated` se réfère — sans elle, « la
        | graine a été republiée après le gel » n'a pas de gel à comparer.
        */
        AuditLog::record(
            action: AuditAction::ChallengePeriodClosed->value,
            summary: $actor !== null
                ? "{$actor->fullName()} a clos la période du challenge « {$challenge->name} »."
                : "Le planificateur a clos la période du challenge « {$challenge->name} » à l'échéance.",
            subject: $challenge,
            by: $actor,
            context: [
                'pool' => $challenge->tickets()->count(),
                'automatic' => $actor === null,
            ],
        );

        if ($challenge->type === ChallengeType::Leaderboard) {
            $this->awardLeaderboard($challenge);
        } else {
            $this->draw->publishSeed($challenge);
        }

        $challenge->refresh();
    }

    /**
     * Classement : les gagnants sont les N premiers par courses terminées sur
     * la période — aucun tirage n'intervient.
     */
    private function awardLeaderboard(Challenge $challenge): void
    {
        if ($challenge->winners()->exists()) {
            return;
        }

        $ranking = (new ChallengeRanking($challenge))
            ->ranked()
            ->limit((int) ($challenge->winners_count ?? 0))
            ->get();

        foreach ($ranking as $row) {
            ChallengeWinner::query()->create([
                'challenge_id' => $challenge->id,
                'driver_id' => $row->id,
                'rank' => (int) $row->place,
                'amount' => $challenge->reward_amount,
            ]);
        }

        $challenge->update(['status' => ChallengeStatus::PayoutPending]);
    }
}
