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
    'gestionnaire' => ['gestionnaire', 8],
    'bonus' => ['bonus', 11],
    'stock' => ['stock', 3],
    // + « Utilisateurs et rôles », qui a quitté les Paramètres.
    'admin' => ['admin', 4],
    'direction' => ['direction', 14],
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

it('only lets admin and direction reach users and roles', function (): void {
    // Le module a quitté les Paramètres : les deux rôles qui les tenaient le
    // suivent, et personne d'autre ne l'hérite.
    expect(moduleAccessUser('admin')->can(BackOfficeModule::Users->permission()))->toBeTrue()
        ->and(moduleAccessUser('direction')->can(BackOfficeModule::Users->permission()))->toBeTrue()
        ->and(moduleAccessUser('gestionnaire')->can(BackOfficeModule::Users->permission()))->toBeFalse()
        ->and(moduleAccessUser('bonus')->can(BackOfficeModule::Users->permission()))->toBeFalse()
        ->and(moduleAccessUser('stock')->can(BackOfficeModule::Users->permission()))->toBeFalse();
});

it('lets an authorised user reach the dashboard', function (): void {
    $this->actingAs(moduleAccessUser('direction'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk();
});

it('only shows dashboard cards for modules the user can reach', function (): void {
    // `gestionnaire` a Chauffeurs et CNPS, mais pas Recharges : la carte des
    // recharges pointerait vers un 403 et exposerait un agrégat interdit.
    $this->actingAs(moduleAccessUser('gestionnaire'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->assertSee(__('backoffice.dashboard.active_drivers'))
        ->assertSee(__('backoffice.dashboard.cnps_month'))
        ->assertDontSee(__('backoffice.dashboard.recharges_today'));
});

it('shows no dashboard cards when no source module is permitted', function (): void {
    // `admin` atteint le tableau de bord mais n'a ni Chauffeurs, ni CNPS, ni
    // Recharges, ni Requêtes : aucune carte ne doit s'afficher.
    $this->actingAs(moduleAccessUser('admin'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->assertDontSee(__('backoffice.dashboard.active_drivers'))
        ->assertDontSee(__('backoffice.dashboard.cnps_month'))
        ->assertDontSee(__('backoffice.dashboard.recharges_today'))
        ->assertSee(__('backoffice.dashboard.no_cards'));
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

it('links every visible module now that no screen is pending', function (): void {
    /*
     * Le journal d'audit était le dernier module sans écran : la pastille
     * « Bientôt » n'a plus de sujet, et toute entrée de la barre latérale doit
     * désormais mener quelque part. L'inverse de l'ancienne assertion, qui
     * guettait la pastille.
     */
    $user = moduleAccessUser('direction');

    $response = $this->actingAs($user)
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->assertSee(BackOfficeModule::Challenges->label())
        ->assertSee(BackOfficeModule::Settings->label())
        ->assertDontSeeText('Bientôt');

    foreach ($user->visibleModules() as $module) {
        $response->assertSee('href="'.route($module->route()).'"', false);
    }
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
