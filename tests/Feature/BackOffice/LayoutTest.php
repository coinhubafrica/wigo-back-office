<?php

/**
 * La coquille de l'application : navigation sémantique, barre latérale
 * escamotable, lien d'évitement et pagination sur la charte.
 */

use App\Enums\BackOfficeModule;
use App\Models\Driver;
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

function layoutUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
