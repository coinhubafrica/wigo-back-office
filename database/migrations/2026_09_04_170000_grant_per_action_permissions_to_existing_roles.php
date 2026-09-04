<?php

use App\Enums\BackOfficeModule;
use App\Enums\Permission as BackOfficePermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Découpe en permissions les gestes qui n'étaient gardés que par l'accès au
     * module.
     *
     * Jusqu'ici, atteindre un module suffisait pour tout y faire : suspendre un
     * conducteur, diffuser une campagne à toute la flotte, exécuter un tirage,
     * créditer des lots, écraser une clé Wave. Chaque geste porte désormais sa
     * permission.
     *
     * **Cette migration ne retire rien à personne** : elle accorde chaque
     * nouveau droit aux rôles qui pouvaient déjà l'exercer, c'est-à-dire ceux
     * qui portent l'accès au module concerné. Sinon un rôle perdrait du jour au
     * lendemain la moitié de son écran, en pleine journée de travail. Le
     * resserrage se fait ensuite depuis « Utilisateurs et rôles », en
     * connaissance de cause.
     *
     * Deux exceptions, où l'on n'accorde pas au-delà de ce qui existait :
     * `challenges.regenerate-seed` et `challenges.approve-surprise` restent aux
     * seuls rôles qui approuvaient déjà (`approveSurpriseChallenge` était
     * `hasRole('direction')`), et `settings.reveal-secrets` ne bouge pas.
     */
    public function up(): void
    {
        foreach (BackOfficePermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = Role::query()->where('guard_name', 'web')->get();

        /*
        | Republier la graine n'est pas distribué avec l'accès au module : c'est
        | le contrôle exercé sur qui exécute les tirages. Il suit donc
        | l'approbation des bonus surprise, qui portait déjà ce rôle d'arbitre.
        */
        $roles
            ->filter(fn (Role $role): bool => $role->hasPermissionTo(
                BackOfficePermission::ChallengesApproveSurprise->value,
            ))
            ->each(fn (Role $role) => $role->givePermissionTo(
                BackOfficePermission::ChallengesRegenerateSeed->value,
            ));

        foreach ($roles as $role) {
            $grants = [];

            foreach ($this->actionsByModule() as $moduleValue => $actions) {
                $module = BackOfficeModule::from($moduleValue);

                // Le rôle pouvait déjà exercer ces gestes : l'accès au module
                // était leur seule garde.
                if (! $role->hasPermissionTo($module->permission())) {
                    continue;
                }

                foreach ($actions as $action) {
                    $grants[] = $action->value;
                }
            }

            if ($grants !== []) {
                $role->givePermissionTo($grants);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $revocable = collect($this->actionsByModule())
            ->flatten()
            ->push(BackOfficePermission::ChallengesRegenerateSeed)
            ->map(fn (BackOfficePermission $permission): string => $permission->value);

        Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->each(function (Role $role) use ($revocable): void {
                foreach ($revocable as $name) {
                    $role->revokePermissionTo($name);
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Les gestes nouvellement gardés, par module dont l'accès les couvrait.
     *
     * `challenges.approve-surprise` et `challenges.regenerate-seed` sont
     * absents à dessein : le premier existait déjà avec sa propre garde, le
     * second est le geste le plus sensible du module (il change le hasard après
     * le gel du vivier) et ne doit pas être distribué automatiquement.
     * `shop.manage-catalogue`, `recharges.reconcile`, `support.reassign`,
     * `users.manage`, `roles.manage` et `settings.reveal-secrets` étaient déjà
     * des droits à part : la migration précédente s'en est chargée.
     *
     * @return array<string, list<BackOfficePermission>>
     */
    private function actionsByModule(): array
    {
        return [
            BackOfficeModule::Drivers->value => [
                BackOfficePermission::DriversSuspend,
            ],
            BackOfficeModule::SupportRequests->value => [
                BackOfficePermission::SupportHandle,
                BackOfficePermission::SupportDismiss,
                BackOfficePermission::SupportManageTemplates,
            ],
            BackOfficeModule::Challenges->value => [
                BackOfficePermission::ChallengesCreate,
                BackOfficePermission::ChallengesClosePeriod,
                BackOfficePermission::ChallengesDraw,
                BackOfficePermission::ChallengesCredit,
                BackOfficePermission::ChallengesManagePrizes,
            ],
            BackOfficeModule::Announcements->value => [
                BackOfficePermission::AnnouncementsManage,
                BackOfficePermission::AnnouncementsPublish,
            ],
            BackOfficeModule::Campaigns->value => [
                BackOfficePermission::CampaignsManage,
                BackOfficePermission::CampaignsSend,
            ],
            BackOfficeModule::ShopOrders->value => [
                BackOfficePermission::ShopFulfilOrders,
                BackOfficePermission::ShopCancelOrder,
            ],
            BackOfficeModule::Settings->value => [
                BackOfficePermission::SettingsManage,
            ],
        ];
    }
};
