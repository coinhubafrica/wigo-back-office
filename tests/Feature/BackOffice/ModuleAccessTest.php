<?php

namespace Tests\Feature\BackOffice;

use App\Enums\BackOfficeModule;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contrôle d'accès aux modules : la barre latérale masque les modules non
 * autorisés, mais c'est le middleware `permission` qui les protège.
 */
class ModuleAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function roleModuleCounts(): array
    {
        return [
            'gestionnaire' => ['gestionnaire', 6],
            'bonus' => ['bonus', 9],
            'stock' => ['stock', 2],
            'admin' => ['admin', 3],
            'direction' => ['direction', 11],
        ];
    }

    #[DataProvider('roleModuleCounts')]
    public function test_each_role_sees_the_expected_number_of_modules(string $role, int $expected): void
    {
        $user = $this->user($role);

        $this->assertCount($expected, $user->visibleModules());
    }

    public function test_the_bonus_role_reaches_the_challenges_module(): void
    {
        $this->assertTrue($this->user('bonus')->can(BackOfficeModule::Challenges->permission()));
    }

    public function test_the_gestionnaire_role_does_not_reach_challenges(): void
    {
        $this->assertFalse($this->user('gestionnaire')->can(BackOfficeModule::Challenges->permission()));
    }

    public function test_only_admin_and_direction_reach_the_settings(): void
    {
        $this->assertTrue($this->user('admin')->can(BackOfficeModule::Settings->permission()));
        $this->assertTrue($this->user('direction')->can(BackOfficeModule::Settings->permission()));
        $this->assertFalse($this->user('bonus')->can(BackOfficeModule::Settings->permission()));
        $this->assertFalse($this->user('stock')->can(BackOfficeModule::Settings->permission()));
    }

    public function test_an_authorised_user_reaches_the_dashboard(): void
    {
        $this->actingAs($this->user('direction'))
            ->get(route(BackOfficeModule::Dashboard->route()))
            ->assertOk();
    }

    public function test_the_dashboard_only_shows_cards_for_modules_the_user_can_reach(): void
    {
        // `gestionnaire` a Chauffeurs et Boutique, mais pas Recharges : la carte
        // des recharges pointerait vers un 403 et exposerait un agrégat interdit.
        $this->actingAs($this->user('gestionnaire'))
            ->get(route(BackOfficeModule::Dashboard->route()))
            ->assertOk()
            ->assertSee(__('backoffice.dashboard.active_drivers'))
            ->assertSee(__('backoffice.dashboard.stock_alerts'))
            ->assertDontSee(__('backoffice.dashboard.recharges_to_replay'));
    }

    public function test_the_dashboard_shows_no_cards_when_no_source_module_is_permitted(): void
    {
        // `admin` atteint le tableau de bord mais n'a ni Chauffeurs, ni Boutique,
        // ni Recharges : aucune carte ne doit s'afficher.
        $this->actingAs($this->user('admin'))
            ->get(route(BackOfficeModule::Dashboard->route()))
            ->assertOk()
            ->assertDontSee(__('backoffice.dashboard.active_drivers'))
            ->assertDontSee(__('backoffice.dashboard.stock_alerts'))
            ->assertDontSee(__('backoffice.dashboard.recharges_to_replay'));
    }

    public function test_a_user_without_the_permission_gets_403_on_direct_access(): void
    {
        // Le rôle `stock` n'a pas `module.dashboard` : l'URL directe est refusée.
        $this->actingAs($this->user('stock'))
            ->get(route(BackOfficeModule::Dashboard->route()))
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_the_login_screen(): void
    {
        $this->get(route(BackOfficeModule::Dashboard->route()))
            ->assertRedirect(route('bo.login'));
    }

    public function test_a_user_disabled_mid_session_is_signed_out(): void
    {
        $user = $this->user('direction');

        $this->actingAs($user)
            ->get(route(BackOfficeModule::Dashboard->route()))
            ->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)
            ->get(route(BackOfficeModule::Dashboard->route()))
            ->assertRedirect(route('bo.login'));

        $this->assertGuest();
    }

    public function test_the_sidebar_lists_the_dashboard_module(): void
    {
        $this->actingAs($this->user('direction'))
            ->get(route(BackOfficeModule::Dashboard->route()))
            ->assertOk()
            ->assertSee(BackOfficeModule::Dashboard->label());
    }

    public function test_the_sidebar_marks_modules_whose_route_does_not_exist_yet_as_coming_soon(): void
    {
        // Le filtre par route livrée est désactivé temporairement (voir
        // layouts/app.blade.php) : tous les modules apparaissent, ceux sans
        // route livrée sont marqués "Bientôt" plutôt que masqués.
        $response = $this->actingAs($this->user('direction'))
            ->get(route(BackOfficeModule::Dashboard->route()))
            ->assertOk();

        $response->assertSee(BackOfficeModule::Challenges->label());
        $response->assertSee(BackOfficeModule::Settings->label());
        $response->assertSeeText('Bientôt');
    }

    public function test_the_sidebar_hides_a_built_module_the_user_cannot_reach(): void
    {
        // `stock` n'a pas `module.dashboard` : l'entrée ne doit pas apparaître.
        $user = $this->user('stock');

        $this->assertNotContains(
            BackOfficeModule::Dashboard,
            $user->visibleModules(),
        );
    }

    public function test_the_topbar_shows_the_user_name_and_role_label(): void
    {
        $user = $this->user('bonus', ['first_name' => 'Sylvain', 'last_name' => 'ADJÉ']);

        $this->actingAs($user)
            ->get(route(BackOfficeModule::Dashboard->route()))
            ->assertOk()
            ->assertSee('Sylvain ADJÉ')
            ->assertSee('Responsable Bonus / Animation');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }
}
