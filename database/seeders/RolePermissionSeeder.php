<?php

namespace Database\Seeders;

use App\Enums\Permission as BackOfficePermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions et rôles initiaux du back-office.
 *
 * Les permissions sont dérivées de l'énumération `Permission` (elles suivent le
 * code). Les rôles, eux, sont administrés dans « Utilisateurs et rôles » : ce
 * seeder ne fait que poser la matrice de départ du cahier des charges, sans
 * écraser les rôles existants.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Le catalogue des droits suit le code : on le synchronise
        // systématiquement, accès aux modules comme actions sensibles.
        foreach (BackOfficePermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        // Le cache doit être vidé APRÈS la création : sinon `syncPermissions`
        // résout les noms sur un cache antérieur aux nouvelles permissions
        // (cas d'un `migrate:fresh --seed`, où les tables viennent d'être
        // recréées).
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->initialRoles() as $name => $definition) {
            $role = Role::findOrCreate($name, 'web');

            $role->forceFill([
                'label' => $definition['label'],
                'description' => $definition['description'],
            ])->save();

            // Rôle déjà personnalisé dans le back-office : on ne touche pas à
            // ses permissions, seul le libellé est rafraîchi.
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions(array_map(
                    fn (BackOfficePermission $permission): string => $permission->value,
                    $definition['permissions'],
                ));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, array{label: string, description: string, permissions: list<BackOfficePermission>}>
     */
    private function initialRoles(): array
    {
        $support = [
            BackOfficePermission::ModuleDashboard,
            BackOfficePermission::ModuleDrivers,
            BackOfficePermission::ModuleVehicles,
            BackOfficePermission::ModuleSupportRequests,
            BackOfficePermission::ModuleCnps,
            BackOfficePermission::ModuleShop,
            BackOfficePermission::ModuleShopOrders,
            BackOfficePermission::ModuleCampaigns,
        ];

        return [
            'gestionnaire' => [
                'label' => 'Gestionnaire plateforme',
                'description' => 'Suivi du parc, support, CNPS et boutique.',
                'permissions' => $support,
            ],
            'bonus' => [
                'label' => 'Responsable Bonus / Animation',
                'description' => 'Gestionnaire plateforme, plus les challenges, paiements et annonces.',
                'permissions' => [
                    ...$support,
                    BackOfficePermission::ModuleChallenges,
                    BackOfficePermission::ModuleRecharges,
                    BackOfficePermission::ModuleAnnouncements,
                    // Rejouer un crédit fait partie du métier du rôle : c'est
                    // lui qui suit les recharges au quotidien.
                    BackOfficePermission::RechargesReconcile,
                ],
            ],
            'stock' => [
                'label' => 'Gestionnaire catalogue',
                'description' => 'Requêtes et boutique uniquement.',
                'permissions' => [
                    BackOfficePermission::ModuleSupportRequests,
                    BackOfficePermission::ModuleShop,
                    BackOfficePermission::ModuleShopOrders,
                    BackOfficePermission::ShopManageCatalogue,
                ],
            ],
            'admin' => [
                'label' => 'Administrateur',
                'description' => "Comptes, paramétrage et journal d'audit.",
                'permissions' => [
                    BackOfficePermission::ModuleDashboard,
                    BackOfficePermission::ModuleUsers,
                    BackOfficePermission::ModuleSettings,
                    BackOfficePermission::ModuleAudit,
                    // L'administrateur tient les comptes et les rôles, mais ne
                    // relève pas les clés d'encaissement : régler un plafond et
                    // lire le secret qui encaisse ne sont pas la même décision.
                    BackOfficePermission::UsersManage,
                    BackOfficePermission::RolesManage,
                ],
            ],
            'direction' => [
                'label' => 'Directeur',
                'description' => 'Tous les modules et toutes les actions sensibles.',
                'permissions' => BackOfficePermission::cases(),
            ],
        ];
    }
}
