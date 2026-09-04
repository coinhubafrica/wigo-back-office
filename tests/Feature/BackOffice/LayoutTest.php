<?php

/**
 * La coquille de l'application : navigation sémantique, barre latérale
 * escamotable, lien d'évitement et pagination sur la charte.
 */

use App\Enums\BackOfficeModule;
use App\Enums\ShopOrderStatus;
use App\Models\Driver;
use App\Models\ShopOrder;
use App\Models\SupportRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('renders the shell with a skip link, list navigation and a menu toggle', function (): void {
    $response = $this->actingAs(layoutUser('direction'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk();

    $response->assertSee('href="#main"', false)
        ->assertSee('id="main"', false)
        ->assertSee('aria-current="page"', false)
        ->assertSee('aria-controls="sidebar"', false)
        ->assertSee('x-data="appShell"', false)
        ->assertSee('x-data="toasts"', false)
        ->assertSee('aria-labelledby="nav-group-support"', false)
        ->assertSee('id="nav-group-support"', false);
});

it('groups drivers and vehicles under Parc, apart from support', function (): void {
    $html = $this->actingAs(layoutUser('direction'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->assertSee('id="nav-group-parc"', false)
        ->getContent();

    // Chauffeurs a quitté Support : les deux entrées du parc se suivent, et
    // « Parc » précède « Support » dans la barre latérale.
    expect($html)->toContain('nav-group-parc')
        ->and(strpos($html, 'nav-group-parc'))->toBeLessThan(strpos($html, 'nav-group-support'));

    foreach ([BackOfficeModule::Drivers, BackOfficeModule::Vehicles] as $module) {
        expect($module->group())->toBe('Parc');
    }

    expect(BackOfficeModule::SupportRequests->group())->toBe('Support');
});

it('paginates in French on the charter tokens', function (): void {
    Driver::factory()->count(25)->create();

    $this->actingAs(layoutUser('direction'))
        ->get(route(BackOfficeModule::Drivers->route()))
        ->assertOk()
        ->assertSee('aria-label="Suivant"', false)
        ->assertSee('aria-label="Précédent"', false)
        ->assertSee('1–20 sur 25')
        ->assertSee('aria-current="page" class="inline-flex size-8', false)
        ->assertDontSee('text-gray-700');
});

it('badges the sidebar with the work waiting in each module', function (): void {
    SupportRequest::factory()->count(2)->create();
    SupportRequest::factory()->pending()->create();
    SupportRequest::factory()->resolved()->create();
    ShopOrder::factory()->count(4)->create();
    ShopOrder::factory()->status(ShopOrderStatus::Delivered)->create();

    $html = $this->actingAs(layoutUser('direction'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->getContent();

    // Trois tickets encore dans la file (deux ouverts, un en attente) et quatre
    // commandes pas encore préparées ; les états terminés ne comptent pas.
    expect(layoutBadge($html, BackOfficeModule::SupportRequests))->toBe('3')
        ->and(layoutBadge($html, BackOfficeModule::ShopOrders))->toBe('4');

    $this->assertStringContainsString('3 en attente', $html);
});

it('leaves a module without a badge when nothing is waiting', function (): void {
    SupportRequest::factory()->resolved()->create();

    $html = $this->actingAs(layoutUser('direction'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->getContent();

    expect(layoutBadge($html, BackOfficeModule::SupportRequests))->toBeNull()
        ->and(layoutBadge($html, BackOfficeModule::ShopOrders))->toBeNull();
});

it('caps the badge at 99+', function (): void {
    SupportRequest::factory()->count(100)->create();

    $html = $this->actingAs(layoutUser('direction'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->getContent();

    expect(layoutBadge($html, BackOfficeModule::SupportRequests))->toBe('99+');

    // Le libellé lu à voix haute garde le compte exact, lui.
    $this->assertStringContainsString('100 en attente', $html);
});

it('shows the role in the sidebar user block rather than the top bar', function (): void {
    $html = $this->actingAs(layoutUser('direction'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->getContent();

    $label = layoutUser('direction')->roleLabel();
    $sidebarEnd = strpos($html, '</aside>');

    expect($label)->not->toBeNull()
        ->and(substr_count($html, (string) $label))->toBe(1)
        ->and(strpos($html, (string) $label))->toBeLessThan($sidebarEnd);
});

/*
|--------------------------------------------------------------------------
| Filtre par permission
|--------------------------------------------------------------------------
*/

it('hides the modules a user cannot reach', function (): void {
    // `stock` porte Requêtes, Produits et Commandes : rien d'autre ne doit
    // apparaître. Une entrée qui répond 403 fait croire à un écran cassé.
    $html = $this->actingAs(layoutUser('stock'))
        ->get(route(BackOfficeModule::Shop->route()))
        ->assertOk()
        ->getContent();

    $sidebar = layoutSidebar((string) $html);

    expect($sidebar)->toContain(layoutLabel(BackOfficeModule::SupportRequests))
        ->toContain(layoutLabel(BackOfficeModule::Shop))
        ->toContain(layoutLabel(BackOfficeModule::ShopOrders))
        ->not->toContain(layoutLabel(BackOfficeModule::Recharges))
        ->not->toContain(layoutLabel(BackOfficeModule::Cnps))
        ->not->toContain(layoutLabel(BackOfficeModule::Users))
        ->not->toContain(layoutLabel(BackOfficeModule::Settings));
});

it('drops a group whose modules are all hidden', function (): void {
    // Sans ses deux entrées, l'intertitre « Finance » surnagerait seul.
    $html = $this->actingAs(layoutUser('stock'))
        ->get(route(BackOfficeModule::Shop->route()))
        ->assertOk()
        ->getContent();

    expect(layoutSidebar((string) $html))
        ->not->toContain('nav-group-finance')
        ->not->toContain('nav-group-systeme')
        ->toContain('nav-group-boutique');
});

it('shows every module to a user who holds them all', function (): void {
    $html = $this->actingAs(layoutUser('direction'))
        ->get(route(BackOfficeModule::Dashboard->route()))
        ->assertOk()
        ->getContent();

    $sidebar = layoutSidebar((string) $html);

    foreach (BackOfficeModule::cases() as $module) {
        expect($sidebar)->toContain(layoutLabel($module));
    }
});

it('gives every module a screen', function (): void {
    /*
     * Le journal d'audit était le dernier module en attente d'écran. La branche
     * « Bientôt » de la barre latérale reste du code vivant pour le prochain,
     * mais elle n'a plus de sujet : cette assertion échouera le jour où l'on
     * ajoutera un module sans sa route, ce qui est précisément le moment où il
     * faut y repenser.
     */
    $pending = collect(BackOfficeModule::cases())
        ->reject(fn (BackOfficeModule $module): bool => Route::has($module->route()))
        ->map(fn (BackOfficeModule $module): string => $module->value)
        ->all();

    expect($pending)->toBe([], 'Ces modules sont annoncés sans écran livré.');
});

it('links the audit journal from the sidebar', function (): void {
    $html = $this->actingAs(layoutUser('admin'))
        ->get(route(BackOfficeModule::Users->route()))
        ->assertOk()
        ->getContent();

    // `layoutLabel` échappe l'apostrophe : « Journal d'audit » s'écrit
    // `Journal d&#039;audit` dans le HTML.
    expect(layoutSidebar((string) $html))
        ->toContain(layoutLabel(BackOfficeModule::Audit))
        ->toContain('href="'.route(BackOfficeModule::Audit->route()).'"');
});

it('hides an unreachable module even when a direct visit would 403', function (): void {
    // Le masquage double le middleware, il ne le remplace pas.
    $user = layoutUser('stock');

    $this->actingAs($user)
        ->get(route(BackOfficeModule::Recharges->route()))
        ->assertForbidden();

    $html = $this->actingAs($user)
        ->get(route(BackOfficeModule::Shop->route()))
        ->assertOk()
        ->getContent();

    expect(layoutSidebar((string) $html))->not->toContain(layoutLabel(BackOfficeModule::Recharges));
});

it('follows a permission granted directly, not only through a role', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo(BackOfficeModule::Cnps->permission());

    $html = $this->actingAs($user)
        ->get(route(BackOfficeModule::Cnps->route()))
        ->assertOk()
        ->getContent();

    expect(layoutSidebar((string) $html))
        ->toContain(layoutLabel(BackOfficeModule::Cnps))
        ->not->toContain(layoutLabel(BackOfficeModule::Drivers));
});

/**
 * La barre latérale seule : le titre du module et son sous-titre répètent des
 * libellés dans la barre supérieure, les chercher dans toute la page ferait
 * passer un module masqué pour visible.
 */
function layoutSidebar(string $html): string
{
    $end = strpos($html, '</aside>');

    return $end === false ? $html : substr($html, 0, $end);
}

/**
 * Libellé du module tel qu'il apparaît dans le HTML.
 *
 * Blade échappe l'apostrophe : « Journal d'audit » s'écrit
 * `Journal d&#039;audit`. Chercher le libellé brut le manquait, et le module
 * passait pour masqué alors qu'il était bien rendu.
 */
function layoutLabel(BackOfficeModule $module): string
{
    return e($module->label());
}

/**
 * Contenu de la pastille de ce module dans la barre latérale, ou `null` s'il
 * n'en porte pas.
 */
function layoutBadge(string $html, BackOfficeModule $module): ?string
{
    // Même échappement que `layoutLabel` : un libellé à apostrophe ne se
    // trouve pas tel quel dans le HTML.
    $start = strpos($html, e($module->label()));

    if ($start === false) {
        return null;
    }

    $item = substr($html, $start, (int) strpos($html, '</li>', $start) - $start);

    preg_match('/tabular-nums[^>]*>\s*([^<\s]+)/', $item, $matches);

    return $matches[1] ?? null;
}

function layoutUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
