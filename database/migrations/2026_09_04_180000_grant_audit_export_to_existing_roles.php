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
     * Accorde l'export du journal d'audit aux rôles qui administrent déjà.
     *
     * `RolePermissionSeeder` ne synchronise qu'à la création d'un rôle : sans
     * cette migration, le droit n'existerait que sur les installations neuves.
     *
     * **On n'élargit pas au-delà de qui voyait déjà le journal.** L'export
     * n'existait pas jusqu'ici : il n'y a donc aucun geste à rattraper, à
     * l'inverse de la migration des permissions par action. Il ne va qu'aux
     * rôles portant `module.audit` — un rôle ne gagne pas un droit sur un écran
     * qu'il ne voit pas, et un fichier qui emporte tout le journal ne se
     * distribue pas par défaut. Le desserrage se fait ensuite depuis
     * « Utilisateurs et rôles », en connaissance de cause.
     */
    public function up(): void
    {
        Permission::findOrCreate(BackOfficePermission::AuditExport->value, 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->filter(fn (Role $role): bool => $role->hasPermissionTo(
                BackOfficeModule::Audit->permission(),
            ))
            ->each(fn (Role $role) => $role->givePermissionTo(
                BackOfficePermission::AuditExport->value,
            ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->revokePermissionTo(
                BackOfficePermission::AuditExport->value,
            ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
