<?php

use App\Enums\BackOfficeModule;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Ouvre le module « Commandes » aux rôles qui voyaient déjà la boutique.
     *
     * Les commandes étaient une sous-page du catalogue et partageaient sa
     * permission ; elles ont désormais la leur. Sans ce rattrapage, un rôle
     * qui accédait aux commandes hier recevrait un 403 aujourd'hui — la
     * séparation ne doit rien retirer à personne.
     *
     * Même raison qu'à l'ajout des véhicules : `RolePermissionSeeder` ne
     * synchronise les permissions qu'à la création d'un rôle, une installation
     * existante n'hérite donc jamais d'un module ajouté après coup.
     */
    public function up(): void
    {
        $orders = Permission::findOrCreate(BackOfficeModule::ShopOrders->permission(), 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->filter(fn (Role $role): bool => $role->hasPermissionTo(BackOfficeModule::Shop->permission()))
            ->each(fn (Role $role) => $role->givePermissionTo($orders));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->revokePermissionTo(BackOfficeModule::ShopOrders->permission()));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
