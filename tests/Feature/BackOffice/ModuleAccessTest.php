<?php

/**
 * Contrôle d'accès aux modules : la barre latérale masque les modules non
 * autorisés, mais c'est le middleware `permission` qui les protège.
 */

use App\Enums\BackOfficeModule;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('shows each role the expected number of modules', function (string $role, int $expected): void {
    $this->assertCount($expected, moduleAccessUser($role)->visibleModules());
})->with([
    'gestionnaire' => ['gestionnaire', 7],
    'bonus' => ['bonus', 10],
    'stock' => ['stock', 3],
    'admin' => ['admin', 3],
    'direction' => ['direction', 12],
]);

it('lets the bonus role reach the challenges module', function (): void {
    $this->assertTrue(moduleAccessUser('bonus')->can(BackOfficeModule::Challenges->permission()));
});

it('does not let the gestionnaire role reach challenges', function (): void {
    $this->assertFalse(moduleAccessUser('gestionnaire')->can(BackOfficeModule::Challenges->permission()));
});

it('only lets admin and direction reach the settings', function (): void {
    $this->assertTrue(moduleAccessUser('admin')->can(BackOfficeModule::Settings->permission()));
    $this->assertTrue(moduleAccessUser('direction')->can(BackOfficeModule::Settings->permission()));
    $this->assertFalse(moduleAccessUser('bonus')->can(BackOfficeModule::Settings->permission()));
    $this->assertFalse(moduleAccessUser('stock')->can(BackOfficeModule::Settings->permission()));
});

it('lets an authorised user reach the dashboard', function (): void {
    $this->actingAs(moduleAccessUser('direction'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk();
});

it('only shows dashboard cards for modules the user can reach', function (): void {
    // `gestionnaire` a Chauffeurs et Boutique, mais pas Recharges : la carte
    // des recharges pointerait vers un 403 et exposerait un agrégat interdit.
    $this->actingAs(moduleAccessUser('gestionnaire'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->assertSee(__('backoffice.dashboard.active_drivers'))
        ->assertSee(__('backoffice.dashboard.inactive_products'))
        ->assertDontSee(__('backoffice.dashboard.recharges_to_replay'));
});

it('shows no dashboard cards when no source module is permitted', function (): void {
    // `admin` atteint le tableau de bord mais n'a ni Chauffeurs, ni Boutique,
    // ni Recharges : aucune carte ne doit s'afficher.
    $this->actingAs(moduleAccessUser('admin'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->assertDontSee(__('backoffice.dashboard.active_drivers'))
        ->assertDontSee(__('backoffice.dashboard.inactive_products'))
        ->assertDontSee(__('backoffice.dashboard.recharges_to_replay'));
});

it('returns 403 on direct access for a user without the permission', function (): void {
    // Le rôle `stock` n'a pas `module.dashboard` : l'URL directe est refusée.
    $this->actingAs(moduleAccessUser('stock'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertForbidden();
});

it('redirects a guest to the login screen', function (): void {
    $this->get(route(BackOfficeModule::Dashboard->route()))
        ->assertRedirect(route('bo.login'));
});

it('signs out a user disabled mid session', function (): void {
    $user = moduleAccessUser('direction');

    $this->actingAs($user)
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user)
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertRedirect(route('bo.login'));

    $this->assertGuest();
});

it('lists the dashboard module in the sidebar', function (): void {
    $this->actingAs(moduleAccessUser('direction'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->assertSee(BackOfficeModule::Dashboard->label());
});

it('marks modules whose route does not exist yet as coming soon', function (): void {
    // Le filtre par route livrée est désactivé temporairement (voir
    // layouts/app.blade.php) : tous les modules apparaissent, ceux sans
    // route livrée sont marqués "Bientôt" plutôt que masqués.
    $response = $this->actingAs(moduleAccessUser('direction'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk();

    $response->assertSee(BackOfficeModule::Challenges->label());
    $response->assertSee(BackOfficeModule::Settings->label());
    $response->assertSeeText('Bientôt');
});

it('hides a built module the user cannot reach', function (): void {
    // `stock` n'a pas `module.dashboard` : l'entrée ne doit pas apparaître.
    $this->assertNotContains(
        BackOfficeModule::Dashboard,
        moduleAccessUser('stock')->visibleModules(),
    );
});

it('shows the user name and role label in the topbar', function (): void {
    $user = moduleAccessUser('bonus', ['first_name' => 'Sylvain', 'last_name' => 'ADJÉ']);

    $this->actingAs($user)
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->assertSee('Sylvain ADJÉ')
        ->assertSee('Responsable Bonus / Animation');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function moduleAccessUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create(['is_active' => true, ...$attributes]);
    $user->assignRole($role);

    return $user;
}
