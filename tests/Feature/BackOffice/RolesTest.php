<?php

/**
 * Rôles et matrice des droits : création, cohérence accès/action,
 * suppression gardée et effet immédiat sur les portails.
 */

use App\Enums\BackOfficeModule;
use App\Enums\Permission as BackOfficePermission;
use App\Livewire\Users\Roles as RolesScreen;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('shows the seeded roles with their permission counts', function (): void {
    $this->actingAs(rolesScreenUser('admin'))
        ->get(route('bo.users.roles'))
        ->assertOk()
        ->assertSee('Gestionnaire catalogue')
        ->assertSee('Directeur');
});

it('creates a role with a slug derived from its label', function (): void {
    Livewire::actingAs(rolesScreenUser('admin'))
        ->test(RolesScreen::class)
        ->call('newRole')
        ->set('label', 'Superviseur de nuit')
        ->set('description', 'Suit les requêtes après 20 h.')
        ->set('selectedPermissions', [
            BackOfficePermission::ModuleSupportRequests->value,
            BackOfficePermission::SupportReassign->value,
        ])
        ->call('save')
        ->assertHasNoErrors();

    $role = Role::query()->where('label', 'Superviseur de nuit')->sole();

    expect($role->name)->toBe('superviseur-de-nuit')
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe([
            BackOfficePermission::ModuleSupportRequests->value,
            BackOfficePermission::SupportReassign->value,
        ]);
});

it('suffixes the technical name rather than overwriting a namesake', function (): void {
    Role::query()->create(['name' => 'superviseur', 'guard_name' => 'web', 'label' => 'Superviseur']);

    Livewire::actingAs(rolesScreenUser('admin'))
        ->test(RolesScreen::class)
        ->call('newRole')
        ->set('label', 'Superviseur')
        ->call('save');

    expect(Role::query()->where('name', 'superviseur-2')->exists())->toBeTrue()
        ->and(Role::query()->where('label', 'Superviseur')->count())->toBe(2);
});

it('keeps the technical name when the label is renamed', function (): void {
    // Le nom technique est la clé des droits déjà accordés : le renommer
    // détacherait les utilisateurs du rôle.
    $role = Role::query()->where('name', 'stock')->sole();

    Livewire::actingAs(rolesScreenUser('admin'))
        ->test(RolesScreen::class)
        ->call('edit', $role->id)
        ->set('label', 'Magasinier')
        ->call('save');

    expect($role->fresh()->name)->toBe('stock')
        ->and($role->fresh()->label)->toBe('Magasinier');
});

it('checking an action also grants the module access it needs', function (): void {
    $component = Livewire::actingAs(rolesScreenUser('admin'))
        ->test(RolesScreen::class)
        ->call('newRole')
        ->call('togglePermission', BackOfficePermission::ShopManageCatalogue->value);

    expect($component->get('selectedPermissions'))
        ->toContain(BackOfficePermission::ShopManageCatalogue->value)
        ->toContain(BackOfficePermission::ModuleShop->value);
});

it('unchecking a module access drops the actions of that module', function (): void {
    $component = Livewire::actingAs(rolesScreenUser('admin'))
        ->test(RolesScreen::class)
        ->call('newRole')
        ->call('togglePermission', BackOfficePermission::ShopManageCatalogue->value)
        ->call('togglePermission', BackOfficePermission::ModuleShop->value);

    expect($component->get('selectedPermissions'))->toBe([]);
});

it('ignores a permission name the enum does not know', function (): void {
    $component = Livewire::actingAs(rolesScreenUser('admin'))
        ->test(RolesScreen::class)
        ->call('newRole')
        ->call('togglePermission', 'inventée.par-le-navigateur');

    expect($component->get('selectedPermissions'))->toBe([]);
});

it('a permission change takes effect on the gates immediately', function (): void {
    // Le cache de spatie doit repartir, sinon la session en cours garde ses
    // anciens droits.
    $user = rolesScreenUser('gestionnaire');

    expect(Gate::forUser($user)->allows('manageCatalogue'))->toBeFalse();

    $role = Role::query()->where('name', 'gestionnaire')->sole();

    Livewire::actingAs(rolesScreenUser('admin'))
        ->test(RolesScreen::class)
        ->call('edit', $role->id)
        ->call('togglePermission', BackOfficePermission::ShopManageCatalogue->value)
        ->call('save');

    expect(Gate::forUser($user->fresh())->allows('manageCatalogue'))->toBeTrue();
});

it('refuses to delete a role somebody still holds', function (): void {
    $role = Role::query()->where('name', 'stock')->sole();
    rolesScreenUser('stock');

    Livewire::actingAs(rolesScreenUser('admin'))
        ->test(RolesScreen::class)
        ->call('delete', $role->id);

    expect(Role::query()->where('name', 'stock')->exists())->toBeTrue();
});

it('deletes an unheld role and logs it', function (): void {
    $role = Role::query()->create(['name' => 'ephemere', 'guard_name' => 'web', 'label' => 'Éphémère']);

    Livewire::actingAs(rolesScreenUser('admin'))
        ->test(RolesScreen::class)
        ->call('delete', $role->id);

    expect(Role::query()->where('name', 'ephemere')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'role.deleted')->exists())->toBeTrue();
});

it('logs the before and after of a permission change', function (): void {
    $role = Role::query()->where('name', 'stock')->sole();

    Livewire::actingAs(rolesScreenUser('admin'))
        ->test(RolesScreen::class)
        ->call('edit', $role->id)
        ->call('togglePermission', BackOfficePermission::ModuleCnps->value)
        ->call('save');

    $log = AuditLog::query()->where('action', 'role.updated')->sole();

    expect($log->context['permissions_before'])->not->toContain(BackOfficePermission::ModuleCnps->value)
        ->and($log->context['permissions_after'])->toContain(BackOfficePermission::ModuleCnps->value)
        ->and($log->subject_type)->toBe('role');
});

it('a user with the module but not roles.manage cannot write', function (): void {
    $reader = User::factory()->create();
    $reader->givePermissionTo(BackOfficeModule::Users->permission());

    $this->actingAs($reader)
        ->get(route('bo.users.roles'))
        ->assertOk();

    Livewire::actingAs($reader)
        ->test(RolesScreen::class)
        ->call('newRole')
        ->assertForbidden();

    Livewire::actingAs($reader)
        ->test(RolesScreen::class)
        ->call('delete', Role::query()->where('name', 'stock')->value('id'))
        ->assertForbidden();
});

it('refuses a permission that is not in the enum when saving', function (): void {
    Livewire::actingAs(rolesScreenUser('admin'))
        ->test(RolesScreen::class)
        ->call('newRole')
        ->set('label', 'Injection')
        ->set('selectedPermissions', ['inventée.par-le-navigateur'])
        ->call('save')
        ->assertHasErrors('selectedPermissions.0');
});

function rolesScreenUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
