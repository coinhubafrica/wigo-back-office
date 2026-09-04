<?php

/**
 * Comptes du back-office : accès, rôles, droits en plus, désactivation et
 * mot de passe provisoire.
 */

use App\Enums\BackOfficeModule;
use App\Enums\Permission as BackOfficePermission;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('a permitted user reaches the users screen', function (): void {
    usersScreenNamed('Mariam', 'KONÉ');

    $this->actingAs(usersScreenUser('admin'))
        ->get(route(BackOfficeModule::Users->route()))
        ->assertOk()
        ->assertSee('Mariam KONÉ');
});

it('a user without the module permission gets 403', function (): void {
    $this->actingAs(usersScreenUser('gestionnaire'))
        ->get(route(BackOfficeModule::Users->route()))
        ->assertForbidden();

    $this->actingAs(usersScreenUser('gestionnaire'))
        ->get(route('bo.users.roles'))
        ->assertForbidden();
});

it('the search matches name, email and phone', function (): void {
    usersScreenNamed('Awa', 'CISSÉ', ['email' => 'awa@example.test', 'phone' => '+2250700000001']);
    usersScreenNamed('Franck', 'ZADI', ['email' => 'franck@example.test', 'phone' => '+2250700000002']);

    Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->set('search', 'awa@example')
        ->assertSee('Awa CISSÉ')
        ->assertDontSee('Franck ZADI')
        ->set('search', '0700000002')
        ->assertSee('Franck ZADI')
        ->assertDontSee('Awa CISSÉ');
});

it('filters on the active state and on a role', function (): void {
    $active = usersScreenNamed('Active', 'PERSONNE');
    $disabled = usersScreenNamed('Inactive', 'PERSONNE', ['is_active' => false]);
    $active->assignRole('stock');
    $disabled->assignRole('bonus');

    $component = Livewire::actingAs(usersScreenUser('admin'))->test(UsersIndex::class);

    $component->call('filterBy', 'inactive')
        ->assertSee('Inactive PERSONNE')
        ->assertDontSee('Active PERSONNE');

    $component->call('filterBy', 'stock')
        ->assertSee('Active PERSONNE')
        ->assertDontSee('Inactive PERSONNE');
});

it('creates a user with roles and issues a one-off password', function (): void {
    $component = Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('newUser')
        ->set('firstName', 'Sylvain')
        ->set('lastName', 'ADJÉ')
        ->set('email', 'sylvain@example.test')
        ->set('selectedRoles', ['gestionnaire'])
        ->call('save')
        ->assertHasNoErrors();

    $created = User::query()->where('email', 'sylvain@example.test')->sole();

    expect($created->fullName())->toBe('Sylvain ADJÉ')
        ->and($created->name)->toBe('Sylvain ADJÉ')
        ->and($created->hasRole('gestionnaire'))->toBeTrue()
        ->and($created->is_active)->toBeTrue();

    // Le mot de passe provisoire est affiché une seule fois, et il ouvre bien
    // le compte.
    $issued = $component->get('issuedPassword');

    expect($issued)->toBeString()
        ->and(Hash::check($issued, $created->password))->toBeTrue();
});

it('refuses an email already used by another account', function (): void {
    User::factory()->create(['email' => 'occupe@example.test']);

    Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('newUser')
        ->set('firstName', 'Doublon')
        ->set('lastName', 'TEST')
        ->set('email', 'occupe@example.test')
        ->call('save')
        ->assertHasErrors(['email' => 'unique']);
});

it('keeps a role-granted permission out of the direct grants', function (): void {
    // `stock` porte déjà `shop.manage-catalogue` : le cocher en plus le
    // laisserait en place après le retrait du rôle.
    $target = User::factory()->create();

    Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('edit', $target->id)
        ->set('selectedRoles', ['stock'])
        ->set('selectedPermissions', [
            BackOfficePermission::ShopManageCatalogue->value,
            BackOfficePermission::RechargesReconcile->value,
        ])
        ->call('save')
        ->assertHasNoErrors();

    $target->refresh();

    expect($target->directPermissionNames())
        ->toBe([BackOfficePermission::RechargesReconcile->value])
        // Le droit hérité reste effectif : il vient du rôle.
        ->and($target->can(BackOfficePermission::ShopManageCatalogue->value))->toBeTrue();

    // Retirer le rôle retire bien le droit hérité, et laisse le droit direct.
    $target->syncRoles([]);

    expect($target->fresh()->can(BackOfficePermission::ShopManageCatalogue->value))->toBeFalse()
        ->and($target->fresh()->can(BackOfficePermission::RechargesReconcile->value))->toBeTrue();
});

it('reports which role grants an inherited permission', function (): void {
    $component = Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('newUser')
        ->set('selectedRoles', ['stock']);

    $inherited = $component->instance()->inheritedPermissions();

    expect($inherited)->toHaveKey(BackOfficePermission::ShopManageCatalogue->value)
        ->and($inherited[BackOfficePermission::ShopManageCatalogue->value])->toBe(['Gestionnaire catalogue']);
});

it('counts effective permissions once even when granted twice', function (): void {
    $component = Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('newUser')
        ->set('selectedRoles', ['stock'])
        ->set('selectedPermissions', [BackOfficePermission::ShopManageCatalogue->value]);

    $counts = $component->instance()->effectiveCount();

    // `stock` porte 4 droits ; celui coché en plus est déjà l'un d'eux.
    expect($counts)->toBe(['total' => 4, 'inherited' => 4, 'direct' => 0]);
});

it('disables an account and logs it', function (): void {
    $target = usersScreenNamed('Adam', 'DESACTIVER');

    Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('toggleActive', $target->id);

    expect($target->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'user.disabled')->exists())->toBeTrue();
});

it('refuses to let a user disable their own account', function (): void {
    $actor = usersScreenUser('admin');

    Livewire::actingAs($actor)
        ->test(UsersIndex::class)
        ->call('toggleActive', $actor->id);

    expect($actor->fresh()->is_active)->toBeTrue();
});

it('a disabled user cannot reach the back-office', function (): void {
    $target = usersScreenUser('admin');

    Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('toggleActive', $target->id);

    $this->actingAs($target->fresh())
        ->get(route(BackOfficeModule::Users->route()))
        ->assertRedirect(route('bo.login'));
});

it('resets a password to a working one-off value and logs it', function (): void {
    $target = User::factory()->create();
    $former = $target->password;

    $component = Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('confirmReset', $target->id)
        ->call('resetPassword');

    $issued = $component->get('issuedPassword');
    $target->refresh();

    expect($issued)->toBeString()
        ->and(Hash::check($issued, $target->password))->toBeTrue()
        ->and($target->password)->not->toBe($former)
        ->and(AuditLog::query()->where('action', 'user.password_reset')->exists())->toBeTrue();
});

it('never writes a password into the audit log', function (): void {
    $component = Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('newUser')
        ->set('firstName', 'Trace')
        ->set('lastName', 'TEST')
        ->set('email', 'trace@example.test')
        ->call('save');

    $issued = $component->get('issuedPassword');
    $log = AuditLog::query()->where('action', 'user.created')->sole();

    expect($issued)->toBeString()
        ->and(json_encode($log->context))->not->toContain($issued)
        ->and($log->context['password_issued'])->toBeTrue();
});

it('logs the before and after of a role change only when it changes', function (): void {
    $target = User::factory()->create();
    $target->assignRole('stock');

    Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('edit', $target->id)
        ->set('selectedRoles', ['bonus'])
        ->call('save');

    $log = AuditLog::query()->where('action', 'user.updated')->sole();

    expect($log->context['roles_before'])->toBe(['stock'])
        ->and($log->context['roles_after'])->toBe(['bonus']);

    // Un enregistrement sans changement de droits n'encombre pas le journal :
    // le contexte repart vide (`AuditLog::record` écrit `null`).
    AuditLog::query()->delete();

    Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('edit', $target->id)
        ->set('firstName', 'Renommé')
        ->call('save');

    $unchanged = AuditLog::query()->where('action', 'user.updated')->sole();

    expect($unchanged->context)->toBeNull();
});

it('a user with the module but not users.manage cannot write', function (): void {
    // Lire la liste des comptes n'est pas les modifier.
    $reader = User::factory()->create();
    $reader->givePermissionTo(BackOfficeModule::Users->permission());

    $this->actingAs($reader)
        ->get(route(BackOfficeModule::Users->route()))
        ->assertOk();

    Livewire::actingAs($reader)
        ->test(UsersIndex::class)
        ->call('newUser')
        ->assertForbidden();

    Livewire::actingAs($reader)
        ->test(UsersIndex::class)
        ->call('toggleActive', User::factory()->create()->id)
        ->assertForbidden();
});

it('refuses a permission that is not in the enum', function (): void {
    Livewire::actingAs(usersScreenUser('admin'))
        ->test(UsersIndex::class)
        ->call('newUser')
        ->set('firstName', 'Injection')
        ->set('lastName', 'TEST')
        ->set('email', 'injection@example.test')
        ->set('selectedPermissions', ['settings.reveal-secrets', 'inventée.par-le-navigateur'])
        ->call('save')
        ->assertHasErrors('selectedPermissions.1');
});

/**
 * `fullName()` compose prénom + nom : la factory tire un `name` au hasard, il
 * faut donc poser les trois champs ensemble pour que l'écran affiche le nom
 * attendu.
 *
 * @param  array<string, mixed>  $attributes
 */
function usersScreenNamed(string $firstName, string $lastName, array $attributes = []): User
{
    return User::factory()->create([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'name' => "{$firstName} {$lastName}",
        ...$attributes,
    ]);
}

function usersScreenUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
