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

it('backs every action permission with a gate', function (): void {
    // Une permission sans portail est une case à cocher sans effet : la
    // matrice des rôles promettrait un droit que rien ne lit.
    $abilities = collect(Gate::abilities())->keys();

    $unbacked = collect(Permission::cases())
        ->reject(fn (Permission $permission): bool => $permission->isModuleAccess())
        ->reject(fn (Permission $permission): bool => $abilities->contains(
            fn (string $ability): bool => Gate::forUser(actionlessUser($permission))->allows($ability),
        ))
        ->map(fn (Permission $permission): string => $permission->value)
        ->values()
        ->all();

    expect($unbacked)->toBe([], 'Ces permissions ne sont lues par aucun Gate::define().');
});

it('guards every mutating Livewire method with a gate', function (): void {
    /*
     * Les modules en lecture seule (Véhicules, CNPS, Tableau de bord) n'ont
     * pas de méthode mutante ; les écrans qui écrivent doivent tous appeler
     * `Gate::authorize`. Ce test lit le code plutôt que d'énumérer les
     * méthodes : un écran ajouté sans garde le fait échouer.
     */
    $writers = [
        'Announcements/Index', 'Campaigns/Index', 'Campaigns/Show',
        'Challenges/Prizes', 'Challenges/Show', 'Challenges/Wizard',
        'Drivers/Show', 'Recharges/Index', 'Settings/Index',
        'Shop/Catalogue', 'Shop/Orders', 'SupportRequests/Index',
        'SupportRequests/Templates', 'Users/Index', 'Users/Roles',
    ];

    $unguarded = [];

    foreach ($writers as $component) {
        $source = file_get_contents(app_path("Livewire/{$component}.php"));

        if (! str_contains((string) $source, 'Gate::authorize')) {
            $unguarded[] = $component;
        }
    }

    expect($unguarded)->toBe([], 'Ces composants écrivent sans Gate::authorize().');
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

/**
 * Utilisateur ne portant que la permission donnée : sert à retrouver le
 * portail qui la lit.
 */
function actionlessUser(Permission $permission): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo($permission->value);

    return $user->fresh();
}
