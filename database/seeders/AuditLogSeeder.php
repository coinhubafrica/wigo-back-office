<?php

namespace Database\Seeders;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Jeu d'essai du journal d'audit.
 *
 * Sans lui, l'écran s'ouvre sur les quelques lignes qu'un développeur a
 * produites en cliquant, toutes de la même action : les filtres paraissent
 * cassés alors qu'ils n'ont rien à filtrer. On étale donc des gestes variés
 * sur trois mois, par plusieurs agents, dont quelques écritures d'automate —
 * de quoi éprouver chaque filtre et la pagination.
 *
 * Les phrases suivent la règle du modèle : figées, autoportantes, nommant leur
 * auteur. Aucune ne contient de secret.
 */
class AuditLogSeeder extends Seeder
{
    public function run(): void
    {
        /*
        | Des agents **actifs** seulement : `UserSeeder` pose aussi un compte
        | désactivé de démonstration, et le prendre ici ferait dire au journal
        | qu'un « Compte Désactivé » a diffusé des campagnes — les phrases sont
        | figées, un nom de fixture y resterait pour toujours.
        */
        $agents = User::query()->active()->orderBy('name')->take(3)->get();

        if ($agents->isEmpty()) {
            return;
        }

        $drivers = Driver::query()->take(4)->get();
        $transaction = Transaction::query()->first();

        foreach ($this->entries($agents, $drivers) as $index => $entry) {
            [$action, $summary, $by, $driver, $context] = $entry;

            // Étalé sur trois mois pour que « 7 jours », « 30 jours » et
            // « 90 jours » ne rendent pas tous le même ensemble.
            $occurredAt = Carbon::now()->subDays($index * 3)->subHours($index % 24);

            AuditLog::query()->create([
                'user_id' => $by?->getKey(),
                'driver_id' => $driver?->getKey(),
                'action' => $action->value,
                'subject_type' => $driver === null ? null : $driver->getMorphClass(),
                'subject_id' => $driver?->getKey(),
                'summary' => $summary,
                'context' => $context === [] ? null : $context,
                'ip_address' => $by === null ? null : fake()->ipv4(),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);
        }

        // Une transaction pour dire qu'une cible peut être autre chose qu'un
        // conducteur — l'écran affiche l'alias de morph tel quel.
        if ($transaction !== null) {
            AuditLog::record(
                action: AuditAction::RechargeReplayed->value,
                summary: "Rejeu de la transaction Wave {$transaction->reference}",
                subject: $transaction,
                by: $agents->first(),
                driver: $transaction->driver,
                context: ['amount' => $transaction->amount],
            );
        }
    }

    /**
     * @param  Collection<int, User>  $agents
     * @param  Collection<int, Driver>  $drivers
     * @return list<array{0: AuditAction, 1: string, 2: User|null, 3: Driver|null, 4: array<string, mixed>}>
     */
    private function entries($agents, $drivers): array
    {
        $first = $agents->first();
        $second = $agents->get(1) ?? $first;
        $third = $agents->get(2) ?? $first;

        $driver = $drivers->first();
        $other = $drivers->get(1) ?? $driver;

        $name = fn (?Driver $subject): string => $subject?->fullName() ?? 'un conducteur';

        return [
            [AuditAction::DriverSuspended, "{$first->fullName()} a suspendu {$name($driver)}.", $first, $driver, ['reason' => 'Notes répétées sous 3,5.']],
            [AuditAction::SettingsSecretRevealed, "{$third->fullName()} a relevé en clair le secret « waveTopupApiKey ».", $third, null, ['field' => 'waveTopupApiKey']],
            [AuditAction::UserDisabled, "{$third->fullName()} a désactivé le compte de {$second->fullName()}.", $third, null, ['is_active_before' => true, 'is_active_after' => false]],
            [AuditAction::CampaignSent, "{$second->fullName()} a diffusé la campagne « Bonus de fin de mois ».", $second, null, ['audience' => 'Tous les conducteurs']],
            [AuditAction::ChallengeDrawn, "{$second->fullName()} a exécuté le tirage du challenge « Semaine 36 ».", $second, null, ['winners' => 3]],
            [AuditAction::RechargeCredited, 'Recharge TX-88214 créditée sur le solde Yango', null, $driver, ['amount' => 15000]],
            [AuditAction::DriverReactivated, "{$first->fullName()} a réactivé {$name($driver)}.", $first, $driver, []],
            [AuditAction::AnnouncementPublished, "{$second->fullName()} a publié l'annonce « Nouvelle boutique de pièces ».", $second, null, []],
            [AuditAction::RoleUpdated, "{$third->fullName()} a modifié le rôle « Responsable Bonus / Animation ».", $third, null, ['permissions_before' => ['challenges.draw'], 'permissions_after' => ['challenges.draw', 'challenges.credit']]],
            [AuditAction::ChallengeSeedRegenerated, "{$first->fullName()} a republié la graine du challenge « Semaine 35 ».", $first, null, ['seed' => 'a3f9c1']],
            [AuditAction::RechargeFleetFailed, 'Crédit Yango refusé pour la recharge TX-88190', null, $other, ['amount' => 5000]],
            [AuditAction::UserPasswordReset, "{$third->fullName()} a réinitialisé le mot de passe de {$second->fullName()}.", $third, null, ['password_issued' => true]],
            [AuditAction::AnnouncementWithdrawn, "{$second->fullName()} a retiré l'annonce « Maintenance du samedi ».", $second, null, []],
            [AuditAction::ChallengePrizeCredited, "{$second->fullName()} a marqué le lot « Bidon d'huile » crédité.", $second, $other, ['value' => 12000]],
            [AuditAction::UserCreated, "{$third->fullName()} a créé le compte de {$first->fullName()}.", $third, null, ['roles' => ['gestionnaire'], 'password_issued' => true]],
            [AuditAction::RechargeMarkedCredited, 'Recharge TX-88101 marquée créditée à la main sur Yango', $first, $driver, ['amount' => 20000, 'note' => 'Confirmé par capture Wave.']],
            [AuditAction::RoleDeleted, "{$third->fullName()} a supprimé le rôle « Stagiaire support ».", $third, null, ['role' => 'stagiaire-support']],
            [AuditAction::AnnouncementDeleted, "{$second->fullName()} a supprimé l'annonce « Ancienne offre ».", $second, null, []],
            [AuditAction::UserUpdated, "{$third->fullName()} a modifié le compte de {$second->fullName()}.", $third, null, ['roles_before' => ['gestionnaire'], 'roles_after' => ['bonus']]],
            [AuditAction::UserEnabled, "{$third->fullName()} a réactivé le compte de {$second->fullName()}.", $third, null, ['is_active_before' => false, 'is_active_after' => true]],
            [AuditAction::RoleCreated, "{$third->fullName()} a créé le rôle « Auditeur ».", $third, null, ['role' => 'auditeur']],
            [AuditAction::DriverSuspended, "{$first->fullName()} a suspendu {$name($other)}.", $first, $other, ['reason' => 'Documents CNPS expirés.']],
            [AuditAction::RechargeReplayed, 'Rejeu de la transaction Wave TX-87990', $first, $other, ['amount' => 7500]],
            [AuditAction::CampaignSent, "{$second->fullName()} a diffusé la campagne « Rappel CNPS ».", $second, null, ['audience' => 'Segment : sans déclaration']],
            [AuditAction::ChallengeDrawn, "{$second->fullName()} a exécuté le tirage du challenge « Semaine 34 ».", $second, null, ['winners' => 5]],
        ];
    }
}
