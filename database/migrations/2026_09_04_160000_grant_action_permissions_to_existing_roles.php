<?php

use App\Enums\Permission as BackOfficePermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Transpose en permissions les droits qui étaient des noms de rôle en dur.
     *
     * Les portails d'actions sensibles répondaient à `hasRole('direction')` ou
     * `hasAnyRole([...])`. Ils consultent désormais une permission : sans ce
     * rattrapage, la direction perdrait du jour au lendemain l'approbation des
     * bonus surprise, le magasinier l'écriture au catalogue, et le nouveau
     * module « Utilisateurs et rôles » rendrait un 403 à tout le monde — y
     * compris à qui pourrait le rouvrir.
     *
     * La correspondance reproduit exactement ce que les portails accordaient
     * hier ; elle ne l'élargit pas. Ensuite, tout se règle à l'écran.
     *
     * Raison de fond, valable pour tout droit ajouté après coup :
     * `RolePermissionSeeder` ne synchronise les permissions qu'à la création
     * d'un rôle (cf. `.ai/rules/vehicles.md`).
     */
    public function up(): void
    {
        // Le catalogue complet, pour que les cases à cocher de l'écran des
        // rôles aient toutes une ligne en base.
        foreach (BackOfficePermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->grants() as $roleName => $permissions) {
            $role = Role::query()
                ->where('guard_name', 'web')
                ->where('name', $roleName)
                ->first();

            // Rôle absent (installation qui l'a supprimé ou renommé) : rien à
            // rattraper, et surtout rien à recréer.
            $role?->givePermissionTo(array_map(
                fn (BackOfficePermission $permission): string => $permission->value,
                $permissions,
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Seules les actions repartent : les accès aux modules préexistaient à
        // cette migration, sauf « Utilisateurs et rôles » qu'elle introduit.
        $revocable = array_filter(
            BackOfficePermission::cases(),
            fn (BackOfficePermission $permission): bool => ! $permission->isModuleAccess()
                || $permission === BackOfficePermission::ModuleUsers,
        );

        Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->each(function (Role $role) use ($revocable): void {
                foreach ($revocable as $permission) {
                    $role->revokePermissionTo($permission->value);
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Ce que chaque rôle recevait par son nom, avant que les portails ne
     * consultent une permission.
     *
     * @return array<string, list<BackOfficePermission>>
     */
    private function grants(): array
    {
        return [
            // `manageCatalogue` : hasAnyRole(['stock', 'direction']).
            'stock' => [
                BackOfficePermission::ShopManageCatalogue,
            ],

            // `reconcileRecharges` : hasAnyRole(['bonus', 'direction']).
            'bonus' => [
                BackOfficePermission::RechargesReconcile,
            ],

            // L'administrateur tenait déjà les Paramètres : les comptes et les
            // rôles quittent cette page pour la leur, il les suit.
            'admin' => [
                BackOfficePermission::ModuleUsers,
                BackOfficePermission::UsersManage,
                BackOfficePermission::RolesManage,
            ],

            // `hasRole('direction')` sur l'approbation des bonus surprise et la
            // réattribution des requêtes, plus tout le reste.
            'direction' => BackOfficePermission::cases(),
        ];
    }
};
