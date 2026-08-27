<?php

namespace Tests\Feature\BackOffice;

use App\Enums\BackOfficeModule;
use App\Livewire\Auth\Login;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_the_login_screen_is_reachable(): void
    {
        $this->get(route('bo.login'))
            ->assertOk()
            ->assertSeeLivewire(Login::class);
    }

    public function test_valid_credentials_authenticate_and_land_on_the_first_module(): void
    {
        $user = $this->user('direction');

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'motdepasse')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route(BackOfficeModule::Dashboard->route()));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_user_is_refused_when_none_of_their_modules_is_built_yet(): void
    {
        // Le rôle `stock` n'a accès qu'à Requêtes et Boutique, dont les routes
        // n'existent pas encore : la connexion doit échouer proprement plutôt
        // que rediriger vers une route absente.
        $user = $this->user('stock');

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'motdepasse')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $user = $this->user('direction');

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'mauvais')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_unknown_email_is_rejected(): void
    {
        Livewire::test(Login::class)
            ->set('email', 'inconnu@atconfortplus.ci')
            ->set('password', 'motdepasse')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        $user = $this->user('gestionnaire', ['is_active' => false]);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'motdepasse')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_user_without_any_module_is_refused(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'motdepasse')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_form_requires_both_fields(): void
    {
        Livewire::test(Login::class)
            ->call('login')
            ->assertHasErrors(['email' => 'required', 'password' => 'required']);
    }

    public function test_the_email_must_be_well_formed(): void
    {
        Livewire::test(Login::class)
            ->set('email', 'pas-un-email')
            ->set('password', 'motdepasse')
            ->call('login')
            ->assertHasErrors(['email' => 'email']);
    }

    public function test_six_attempts_are_throttled(): void
    {
        $user = $this->user('direction');

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
    }

    public function test_logout_ends_the_session(): void
    {
        $user = $this->user('direction');

        $this->actingAs($user)
            ->post(route('bo.logout'))
            ->assertRedirect(route('bo.login'));

        $this->assertGuest();
    }

    public function test_an_authenticated_user_is_kept_away_from_the_login_screen(): void
    {
        $this->actingAs($this->user('direction'))
            ->get(route('bo.login'))
            ->assertRedirect(route(BackOfficeModule::Dashboard->route()));
    }

    public function test_the_root_url_does_not_loop_for_an_authenticated_user(): void
    {
        // `/` redirects guests to `/login`; `/login`'s `guest` middleware then
        // redirects an authenticated visitor away. Without an explicit
        // `redirectUsersTo` target, that target defaults to `home` (`/`),
        // which loops forever. Regression for ERR_TOO_MANY_REDIRECTS.
        $this->actingAs($this->user('direction'))
            ->get('/')
            ->assertRedirect('/login');

        $this->actingAs($this->user('direction'))
            ->get('/login')
            ->assertRedirect(route(BackOfficeModule::Dashboard->route()));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create([
            'password' => 'motdepasse',
            'is_active' => true,
            ...$attributes,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
