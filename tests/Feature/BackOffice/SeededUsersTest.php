<?php

namespace Tests\Feature\BackOffice;

use App\Enums\BackOfficeModule;
use App\Livewire\Auth\Login;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le jeu d'essai doit permettre de se connecter réellement : ces tests
 * garantissent que les identifiants documentés fonctionnent.
 */
class SeededUsersTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, UserSeeder::class]);
    }

    public function test_the_seeder_creates_one_user_per_role_plus_a_disabled_one(): void
    {
        $this->assertSame(6, User::count());
        $this->assertSame(5, User::query()->active()->count());
    }

    public function test_the_direction_account_signs_in_with_the_documented_password(): void
    {
        Livewire::test(Login::class)
            ->set('email', 'direction@atconfortplus.ci')
            ->set('password', 'wigo2026')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route(BackOfficeModule::Dashboard->route()));

        $this->assertAuthenticated();
    }

    public function test_the_disabled_account_cannot_sign_in(): void
    {
        Livewire::test(Login::class)
            ->set('email', 'desactive@atconfortplus.ci')
            ->set('password', 'wigo2026')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(UserSeeder::class);

        $this->assertSame(6, User::count());
    }

    public function test_each_seeded_user_carries_exactly_one_role(): void
    {
        User::with('roles')->get()->each(
            fn (User $user) => $this->assertCount(1, $user->roles, "{$user->email} devrait porter un seul rôle."),
        );
    }
}
