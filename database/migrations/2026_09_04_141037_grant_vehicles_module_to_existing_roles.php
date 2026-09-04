<?php

use App\Enums\BackOfficeModule;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Ouvre le nouveau module « Véhicules » aux rôles déjà en place.
     *
     * `RolePermissionSeeder` ne synchronise les permissions qu'à la création
     * d'un rôle (`wasRecentlyCreated`) — volontairement, pour ne pas écraser
     * un rôle affiné à la main dans le back-office. Conséquence : une
     * installation existante n'hérite jamais d'un module ajouté après coup, et
     * la page rendrait un 403 à tout le monde.
     *
     * On n'accorde qu'aux rôles qui voient déjà les chauffeurs : les deux
     * écrans forment le même métier (le parc). `stock` et `admin` ne sont pas
     * concernés, et un rôle à qui un administrateur a retiré les chauffeurs ne
     * se voit rien rendre.
     */
    public function up(): void
    {
        $vehicles = Permission::findOrCreate(BackOfficeModule::Vehicles->permission(), 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->filter(fn (Role $role): bool => $role->hasPermissionTo(BackOfficeModule::Drivers->permission()))
            ->each(fn (Role $role) => $role->givePermissionTo($vehicles));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->revokePermissionTo(BackOfficeModule::Vehicles->permission()));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
