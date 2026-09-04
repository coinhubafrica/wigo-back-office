<?php

namespace Database\Seeders;

use App\Enums\BackOfficeModule;
use App\Support\RevealsSecrets;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions et rôles initiaux du back-office.
 *
 * Les permissions sont dérivées des modules (elles suivent le code). Les rôles,
 * eux, sont administrés dans Paramètres : ce seeder ne fait que poser la matrice
 * de départ du cahier des charges, sans écraser les rôles existants.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Une permission par module : celles-ci suivent le code, on les
        // synchronise systématiquement.
        foreach (BackOfficeModule::cases() as $module) {
            Permission::findOrCreate($module->permission(), 'web');
        }

        // Lire une clé d'API en clair est une action à part : accéder aux
        // Paramètres pour régler un plafond n'implique pas de pouvoir relever
        // les secrets d'encaissement. Deux droits, deux décisions.
        Permission::findOrCreate(RevealsSecrets::PERMISSION, 'web');

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
                $role->syncPermissions([
                    ...array_map(
                        fn (BackOfficeModule $module): string => $module->permission(),
                        $definition['modules'],
                    ),
                    ...($definition['reveals_secrets'] ?? false ? [RevealsSecrets::PERMISSION] : []),
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, array{label: string, description: string, modules: list<BackOfficeModule>, reveals_secrets?: bool}>
     */
    private function initialRoles(): array
    {
        $support = [
            BackOfficeModule::Dashboard,
            BackOfficeModule::Drivers,
            BackOfficeModule::Vehicles,
            BackOfficeModule::SupportRequests,
            BackOfficeModule::Cnps,
            BackOfficeModule::Shop,
            BackOfficeModule::ShopOrders,
            BackOfficeModule::Campaigns,
        ];

        return [
            'gestionnaire' => [
                'label' => 'Gestionnaire plateforme',
                'description' => 'Suivi du parc, support, CNPS et boutique.',
                'modules' => $support,
            ],
            'bonus' => [
                'label' => 'Responsable Bonus / Animation',
                'description' => 'Gestionnaire plateforme, plus les challenges, paiements et annonces.',
                'modules' => [
                    ...$support,
                    BackOfficeModule::Challenges,
                    BackOfficeModule::Recharges,
                    BackOfficeModule::Announcements,
                ],
            ],
            'stock' => [
                'label' => 'Gestionnaire catalogue',
                'description' => 'Requêtes et boutique uniquement.',
                'modules' => [
                    BackOfficeModule::SupportRequests,
                    BackOfficeModule::Shop,
                    BackOfficeModule::ShopOrders,
                ],
            ],
            'admin' => [
                'label' => 'Administrateur',
                'description' => "Paramétrage et journal d'audit.",
                'modules' => [
                    BackOfficeModule::Dashboard,
                    BackOfficeModule::Settings,
                    BackOfficeModule::Audit,
                ],
            ],
            'direction' => [
                'label' => 'Directeur',
                'description' => 'Accès à tous les modules.',
                'modules' => BackOfficeModule::cases(),
                // Seule la direction relève les clés en clair par défaut ; le
                // droit s'attribue ensuite rôle par rôle depuis Paramètres.
                'reveals_secrets' => true,
            ],
        ];
    }
}
