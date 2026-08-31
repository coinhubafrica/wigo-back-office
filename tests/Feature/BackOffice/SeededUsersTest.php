<?php

use App\Enums\BackOfficeModule;
use App\Livewire\Auth\Login;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UserSeeder;
use Livewire\Livewire;

/**
 * Le jeu d'essai doit permettre de se connecter réellement : ces tests
 * garantissent que les identifiants documentés fonctionnent.
 */
beforeEach(function (): void {
    $this->seed([RolePermissionSeeder::class, UserSeeder::class]);
});

it('the seeder creates one user per role plus a disabled one', function (): void {
    $this->assertSame(6, User::count());
    $this->assertSame(5, User::query()->active()->count());
});

it('the direction account signs in with the documented password', function (): void {
    Livewire::test(Login::class)
        ->set('email', 'direction@atconfortplus.ci')
        ->set('password', 'wigo2026')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route(BackOfficeModule::Dashboard->route()));

    $this->assertAuthenticated();
});

it('the disabled account cannot sign in', function (): void {
    Livewire::test(Login::class)
        ->set('email', 'desactive@atconfortplus.ci')
        ->set('password', 'wigo2026')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('the seeder is idempotent', function (): void {
    $this->seed(UserSeeder::class);

    $this->assertSame(6, User::count());
});

it('each seeded user carries exactly one role', function (): void {
    User::with('roles')->get()->each(
        fn (User $user) => $this->assertCount(1, $user->roles, "{$user->email} devrait porter un seul rôle."),
    );
});
