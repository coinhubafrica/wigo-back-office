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

/**
 * Contenu de la pastille de ce module dans la barre latérale, ou `null` s'il
 * n'en porte pas.
 */
function layoutBadge(string $html, BackOfficeModule $module): ?string
{
    $start = strpos($html, $module->label());

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
