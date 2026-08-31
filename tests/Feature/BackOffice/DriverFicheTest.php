<?php

namespace Tests\Feature\BackOffice;

use App\Enums\DriverStatus;
use App\Livewire\Drivers\Show;
use App\Models\Driver;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DriverFicheTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_permitted_user_reaches_the_driver_fiche(): void
    {
        $driver = Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);

        $this->actingAs($this->user('direction'))
            ->get(route('bo.drivers.show', $driver))
            ->assertOk()
            ->assertSee('COMBA');
    }

    public function test_a_user_without_the_permission_gets_403_on_the_fiche(): void
    {
        $driver = Driver::factory()->create();

        $this->actingAs($this->user('stock'))
            ->get(route('bo.drivers.show', $driver))
            ->assertForbidden();
    }

    public function test_the_fiche_shows_the_photo_when_the_driver_has_one(): void
    {
        $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

        Livewire::actingAs($this->user('direction'))
            ->test(Show::class, ['driver' => $driver])
            ->assertSee(route('bo.drivers.photo', $driver), escape: false);
    }

    public function test_the_fiche_falls_back_to_the_initials_without_a_photo(): void
    {
        $driver = Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA', 'photo_url' => null]);

        Livewire::actingAs($this->user('direction'))
            ->test(Show::class, ['driver' => $driver])
            ->assertDontSee(route('bo.drivers.photo', $driver), escape: false)
            ->assertSee('AC');
    }

    public function test_the_fiche_offers_no_photo_moderation_control(): void
    {
        $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

        Livewire::actingAs($this->user('direction'))
            ->test(Show::class, ['driver' => $driver])
            ->assertDontSee('approvePhoto')
            ->assertDontSee('rejectPhoto');
    }

    public function test_the_photo_route_streams_the_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('driver-photos/selfie.jpg', 'binaire');

        $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

        $this->actingAs($this->user('direction'))
            ->get(route('bo.drivers.photo', $driver))
            ->assertOk();
    }

    public function test_the_photo_route_404s_without_a_photo(): void
    {
        $driver = Driver::factory()->create(['photo_url' => null]);

        $this->actingAs($this->user('direction'))
            ->get(route('bo.drivers.photo', $driver))
            ->assertNotFound();
    }

    public function test_the_photo_route_is_closed_without_the_drivers_permission(): void
    {
        $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

        $this->actingAs($this->user('stock'))
            ->get(route('bo.drivers.photo', $driver))
            ->assertForbidden();
    }

    public function test_suspending_a_driver_requires_a_reason(): void
    {
        $driver = Driver::factory()->create(['status' => DriverStatus::Active]);

        Livewire::actingAs($this->user('direction'))
            ->test(Show::class, ['driver' => $driver])
            ->set('showSuspendForm', true)
            ->set('suspensionReason', '')
            ->call('suspend')
            ->assertHasErrors(['suspensionReason' => 'required']);

        $this->assertSame(DriverStatus::Active, $driver->fresh()->status);
    }

    public function test_suspending_a_driver_sets_the_status_and_reason(): void
    {
        $driver = Driver::factory()->create(['status' => DriverStatus::Active]);

        Livewire::actingAs($this->user('direction'))
            ->test(Show::class, ['driver' => $driver])
            ->set('showSuspendForm', true)
            ->set('suspensionReason', 'Documents expirés')
            ->call('suspend');

        $driver->refresh();
        $this->assertSame(DriverStatus::Suspended, $driver->status);
        $this->assertSame('Documents expirés', $driver->suspension_reason);
    }

    public function test_reactivating_a_suspended_driver_clears_the_reason(): void
    {
        $driver = Driver::factory()->suspended('Documents non conformes')->create();

        Livewire::actingAs($this->user('direction'))
            ->test(Show::class, ['driver' => $driver])
            ->call('confirmReactivate')
            ->assertSet('confirmingReactivation', true)
            ->call('reactivate')
            ->assertSet('confirmingReactivation', false);

        $driver->refresh();
        $this->assertSame(DriverStatus::Active, $driver->status);
        $this->assertNull($driver->suspension_reason);
    }

    public function test_cancelling_the_reactivation_leaves_the_driver_suspended(): void
    {
        $driver = Driver::factory()->suspended('Documents non conformes')->create();

        Livewire::actingAs($this->user('direction'))
            ->test(Show::class, ['driver' => $driver])
            ->call('confirmReactivate')
            ->call('cancelReactivate')
            ->assertSet('confirmingReactivation', false);

        $driver->refresh();
        $this->assertSame(DriverStatus::Suspended, $driver->status);
        $this->assertSame('Documents non conformes', $driver->suspension_reason);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
