<?php

/**
 * Le catalogue des droits suit le code : ce garde-fou empêche l'énumération et
 * les modules de divergent, et vérifie que chaque portail lit une permission
 * plutôt qu'un nom de rôle.
 */

use App\Enums\BackOfficeModule;
use App\Enums\Permission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('gives every module a permission case', function (): void {
    foreach (BackOfficeModule::cases() as $module) {
        expect(Permission::tryFrom("module.{$module->value}"))
            ->not->toBeNull("Ajouter le cas `module.{$module->value}` à l'énumération Permission.");
    }
});

it('attaches every permission to a module and labels it', function (): void {
    foreach (Permission::cases() as $permission) {
        expect($permission->belongsTo())->toBeInstanceOf(BackOfficeModule::class)
            ->and($permission->label())->not->toBeEmpty();
    }
});

it('seeds every permission of the enum', function (): void {
    $seeded = Spatie\Permission\Models\Permission::query()
        ->where('guard_name', 'web')
        ->pluck('name')
        ->all();

    foreach (Permission::names() as $name) {
        expect($seeded)->toContain($name);
    }
});

it('resolves each sensitive gate from a permission, not a role name', function (string $ability, Permission $permission): void {
    $granted = User::factory()->create();
    $granted->givePermissionTo($permission->value);

    $denied = User::factory()->create();

    expect(Gate::forUser($granted)->allows($ability))->toBeTrue()
        ->and(Gate::forUser($denied)->allows($ability))->toBeFalse();
})->with([
    'approveSurpriseChallenge' => ['approveSurpriseChallenge', Permission::ChallengesApproveSurprise],
    'reconcileRecharges' => ['reconcileRecharges', Permission::RechargesReconcile],
    'manageCatalogue' => ['manageCatalogue', Permission::ShopManageCatalogue],
    'reassignSupportRequest' => ['reassignSupportRequest', Permission::SupportReassign],
    'manageUsers' => ['manageUsers', Permission::UsersManage],
    'manageRoles' => ['manageRoles', Permission::RolesManage],
]);

it('keeps the abilities each seeded role used to hold by its name', function (string $role, string $ability): void {
    $user = User::factory()->create();
    $user->assignRole($role);

    expect(Gate::forUser($user)->allows($ability))->toBeTrue();
})->with([
    // La migration de bascule ne doit rien retirer à personne.
    'direction approuve les bonus surprise' => ['direction', 'approveSurpriseChallenge'],
    'direction réattribue une requête' => ['direction', 'reassignSupportRequest'],
    'bonus réconcilie les recharges' => ['bonus', 'reconcileRecharges'],
    'direction réconcilie les recharges' => ['direction', 'reconcileRecharges'],
    'stock gère le catalogue' => ['stock', 'manageCatalogue'],
    'direction gère le catalogue' => ['direction', 'manageCatalogue'],
]);

it('does not let a module access imply its actions', function (): void {
    // Lire le journal des recharges n'est pas rejouer un crédit.
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::ModuleRecharges->value);

    expect(Gate::forUser($user)->allows('reconcileRecharges'))->toBeFalse();
});
