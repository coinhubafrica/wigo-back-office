<?php

use App\Enums\BackOfficeModule;
use App\Livewire\Auth\Login;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('the login screen is reachable', function (): void {
    $this->get(route('bo.login'))
        ->assertOk()
        ->assertSeeLivewire(Login::class);
});

it('valid credentials authenticate and land on the first module', function (): void {
    $user = loginUser('direction');

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'motdepasse')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route(BackOfficeModule::Dashboard->route()));

    $this->assertAuthenticatedAs($user);
    $this->assertNotNull($user->fresh()->last_login_at);
});

it('a user is refused when none of their modules is built yet', function (): void {
    // Un compte dont aucun module accessible n'a de route doit échouer.
    // `Audit` est le dernier module non livré ; le jour où il l'est, ce test
    // n'aura plus de module à lui donner et devra être repensé.
    // proprement plutôt que rediriger vers une route absente. Les rôles
    // seedés ont tous au moins un module construit : on fabrique donc un
    // rôle ne portant qu'une permission encore sans écran.
    $role = Role::findOrCreate('campagnes-seules', 'web');
    $role->givePermissionTo(BackOfficeModule::Audit->permission());

    $user = User::factory()->create(['is_active' => true, 'password' => Hash::make('motdepasse')]);
    $user->assignRole($role);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'motdepasse')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('the stock role lands on the support queue', function (): void {
    // `stock` n'a pas de tableau de bord : sa première route disponible est
    // la file des requêtes, construite depuis.
    $user = loginUser('stock');

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'motdepasse')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route(BackOfficeModule::SupportRequests->route()));

    $this->assertAuthenticatedAs($user);
});

it('a wrong password is rejected', function (): void {
    $user = loginUser('direction');

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'mauvais')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('an unknown email is rejected', function (): void {
    Livewire::test(Login::class)
        ->set('email', 'inconnu@atconfortplus.ci')
        ->set('password', 'motdepasse')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('a disabled account cannot sign in', function (): void {
    $user = loginUser('gestionnaire', ['is_active' => false]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'motdepasse')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('a user without any module is refused', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'motdepasse')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('the form requires both fields', function (): void {
    Livewire::test(Login::class)
        ->call('login')
        ->assertHasErrors(['email' => 'required', 'password' => 'required']);
});

it('the email must be well formed', function (): void {
    Livewire::test(Login::class)
        ->set('email', 'pas-un-email')
        ->set('password', 'motdepasse')
        ->call('login')
        ->assertHasErrors(['email' => 'email']);
});

it('six attempts are throttled', function (): void {
    $user = loginUser('direction');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'mauvais')
            ->call('login')
            ->assertHasErrors('email');
    }

    // Le 6ᵉ essai est bloqué : même le bon mot de passe est refusé.
    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'motdepasse')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
    RateLimiter::clear(mb_strtolower($user->email).'|127.0.0.1');
});

it('logout ends the session', function (): void {
    $user = loginUser('direction');

    $this->actingAs($user)
        ->post(route('bo.logout'))
        ->assertRedirect(route('bo.login'));

    $this->assertGuest();
});

it('an authenticated user is kept away from the login screen', function (): void {
    $this->actingAs(loginUser('direction'))
        ->get(route('bo.login'))
        ->assertRedirect(route(BackOfficeModule::Dashboard->route()));
});

it('the root url does not loop for an authenticated user', function (): void {
    // `/` redirects guests to `/login`; `/login`'s `guest` middleware then
    // redirects an authenticated visitor away. Without an explicit
    // `redirectUsersTo` target, that target defaults to `home` (`/`),
    // which loops forever. Regression for ERR_TOO_MANY_REDIRECTS.
    $this->actingAs(loginUser('direction'))
        ->get('/')
        ->assertRedirect('/login');

    $this->actingAs(loginUser('direction'))
        ->get('/login')
        ->assertRedirect(route(BackOfficeModule::Dashboard->route()));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function loginUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create([
        'password' => 'motdepasse',
        'is_active' => true,
        ...$attributes,
    ]);

    $user->assignRole($role);

    return $user;
}
