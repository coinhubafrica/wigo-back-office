<?php

namespace Tests\Feature\BackOffice;

use App\Enums\BackOfficeModule;
use App\Enums\DriverStatus;
use App\Livewire\Drivers\Index;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DriversIndexTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_permitted_user_reaches_the_drivers_list(): void
    {
        Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);

        $this->actingAs($this->user('direction'))
            ->get(route(BackOfficeModule::Drivers->route()))
            ->assertOk()
            ->assertSee('COMBA');
    }

    public function test_a_user_without_the_permission_gets_403(): void
    {
        Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);

        $this->actingAs($this->user('stock'))
            ->get(route(BackOfficeModule::Drivers->route()))
            ->assertForbidden();
    }

    public function test_the_search_filters_by_name(): void
    {
        Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);
        Driver::factory()->create(['first_name' => 'Mariam', 'last_name' => 'TRAORE']);

        Livewire::actingAs($this->user('direction'))
            ->test(Index::class)
            ->set('search', 'TRAORE')
            ->assertSee('TRAORE')
            ->assertDontSee('COMBA');
    }

    public function test_the_search_filters_by_plate_number(): void
    {
        $withPlate = Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);
        Vehicle::factory()->for($withPlate)->create(['plate_number' => 'AA-567-HJ']);
        Driver::factory()->create(['first_name' => 'Mariam', 'last_name' => 'TRAORE']);

        Livewire::actingAs($this->user('direction'))
            ->test(Index::class)
            ->set('search', 'AA-567')
            ->assertSee('COMBA')
            ->assertDontSee('TRAORE');
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA', 'status' => DriverStatus::Active]);
        Driver::factory()->suspended()->create(['first_name' => 'Mariam', 'last_name' => 'TRAORE']);

        Livewire::actingAs($this->user('direction'))
            ->test(Index::class)
            ->call('filterByStatus', DriverStatus::Suspended->value)
            ->assertSee('TRAORE')
            ->assertDontSee('COMBA');
    }

    public function test_reset_filters_clears_search_and_status(): void
    {
        Livewire::actingAs($this->user('direction'))
            ->test(Index::class)
            ->set('search', 'zzz')
            ->call('filterByStatus', DriverStatus::Suspended->value)
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('status', null);
    }

    public function test_the_empty_state_shows_when_no_driver_matches(): void
    {
        Livewire::actingAs($this->user('direction'))
            ->test(Index::class)
            ->set('search', 'nobody-matches-this')
            ->assertSee('Aucun conducteur ne correspond');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
