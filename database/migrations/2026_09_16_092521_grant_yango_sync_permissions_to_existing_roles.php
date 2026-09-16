<?php

use App\Enums\Permission as BackOfficePermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Accorde le rattrapage Yango — l'accès à l'écran, le rafraîchissement
     * d'une fiche, le rejeu d'une période — aux rôles qui suivent déjà le
     * parc.
     *
     * `RolePermissionSeeder` ne synchronise les permissions qu'à la *création*
     * d'un rôle, pour ne pas écraser un rôle affiné à la main. Sans ce
     * rattrapage, les cases existeraient dans la matrice mais personne ne les
     * aurait : l'écran répondrait 403 à tout le monde, y compris « direction »,
     * alors que les tests passent sur une base neuve.
     *
     * Le critère est `module.drivers` : qui suit les conducteurs est celui qui
     * a le conducteur au téléphone et constate que sa fiche est en retard.
     */
    public function up(): void
    {
        foreach ($this->granted() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /*
         * `with('permissions')` n'est pas une optimisation : `givePermissionTo`
         * relit la relation après chaque écriture, et `Model::preventLazyLoading()`
         * — actif hors production — lève sur cette relecture. Les trois droits
         * partent donc en un seul appel, sur un rôle dont la relation est déjà
         * chargée.
         */
        $roles = Role::query()
            ->with('permissions')
            ->where('guard_name', 'web')
            ->get()
            ->filter(fn (Role $role): bool => $role->hasPermissionTo(BackOfficePermission::ModuleDrivers->value));

        $granted = array_map(
            fn (BackOfficePermission $permission): string => $permission->value,
            $this->granted(),
        );

        foreach ($roles as $role) {
            $role->givePermissionTo($granted);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $revoked = array_map(
            fn (BackOfficePermission $permission): string => $permission->value,
            $this->granted(),
        );

        Role::query()
            ->with('permissions')
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->revokePermissionTo($revoked));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<BackOfficePermission>
     */
    private function granted(): array
    {
        return [
            BackOfficePermission::ModuleYangoSync,
            BackOfficePermission::YangoRefreshRecord,
            BackOfficePermission::YangoResyncPeriod,
        ];
    }
};
