<?php

use App\Enums\Permission as BackOfficePermission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Accorde « Resynchroniser les courses du challenge » aux rôles qui
     * tiennent déjà le cycle de vie d'un challenge.
     *
     * `RolePermissionSeeder` ne synchronise les permissions qu'à la *création*
     * d'un rôle : sans ce rattrapage, la case existerait dans la matrice mais
     * personne ne l'aurait, et le bouton resterait invisible sur une
     * installation en place.
     *
     * Qui clôt une période peut resynchroniser : c'est le même geste vu de
     * l'autre bout — s'assurer que le vivier est complet avant de le geler.
     */
    public function up(): void
    {
        Permission::findOrCreate(BackOfficePermission::ChallengesResyncOrders->value, 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->filter(fn (Role $role): bool => $role->hasPermissionTo(BackOfficePermission::ChallengesClosePeriod->value));

        foreach ($roles as $role) {
            $role->givePermissionTo(BackOfficePermission::ChallengesResyncOrders->value);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->revokePermissionTo(BackOfficePermission::ChallengesResyncOrders->value));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
