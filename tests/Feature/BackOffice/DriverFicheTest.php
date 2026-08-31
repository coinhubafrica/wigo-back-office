<?php

use App\Enums\DriverStatus;
use App\Livewire\Drivers\Show;
use App\Models\Driver;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('a permitted user reaches the driver fiche', function (): void {
    $driver = Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);

    $this->actingAs(driverFicheUser('direction'))
        ->get(route('bo.drivers.show', $driver))
        ->assertOk()
        ->assertSee('COMBA');
});

it('a user without the permission gets 403 on the fiche', function (): void {
    $driver = Driver::factory()->create();

    $this->actingAs(driverFicheUser('stock'))
        ->get(route('bo.drivers.show', $driver))
        ->assertForbidden();
});

it('the fiche shows the photo when the driver has one', function (): void {
    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertSee(route('bo.drivers.photo', $driver), escape: false);
});

it('the fiche falls back to the initials without a photo', function (): void {
    $driver = Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA', 'photo_url' => null]);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertDontSee(route('bo.drivers.photo', $driver), escape: false)
        ->assertSee('AC');
});

it('the fiche offers no photo moderation control', function (): void {
    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->assertDontSee('approvePhoto')
        ->assertDontSee('rejectPhoto');
});

it('the photo route streams the file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('driver-photos/selfie.jpg', 'binaire');

    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

    $this->actingAs(driverFicheUser('direction'))
        ->get(route('bo.drivers.photo', $driver))
        ->assertOk();
});

it('the photo route 404s without a photo', function (): void {
    $driver = Driver::factory()->create(['photo_url' => null]);

    $this->actingAs(driverFicheUser('direction'))
        ->get(route('bo.drivers.photo', $driver))
        ->assertNotFound();
});

it('the photo route is closed without the drivers permission', function (): void {
    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/selfie.jpg']);

    $this->actingAs(driverFicheUser('stock'))
        ->get(route('bo.drivers.photo', $driver))
        ->assertForbidden();
});

it('suspending a driver requires a reason', function (): void {
    $driver = Driver::factory()->create(['status' => DriverStatus::Active]);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->set('showSuspendForm', true)
        ->set('suspensionReason', '')
        ->call('suspend')
        ->assertHasErrors(['suspensionReason' => 'required']);

    $this->assertSame(DriverStatus::Active, $driver->fresh()->status);
});

it('suspending a driver sets the status and reason', function (): void {
    $driver = Driver::factory()->create(['status' => DriverStatus::Active]);

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->set('showSuspendForm', true)
        ->set('suspensionReason', 'Documents expirés')
        ->call('suspend');

    $driver->refresh();
    $this->assertSame(DriverStatus::Suspended, $driver->status);
    $this->assertSame('Documents expirés', $driver->suspension_reason);
});

it('reactivating a suspended driver clears the reason', function (): void {
    $driver = Driver::factory()->suspended('Documents non conformes')->create();

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->call('confirmReactivate')
        ->assertSet('confirmingReactivation', true)
        ->call('reactivate')
        ->assertSet('confirmingReactivation', false);

    $driver->refresh();
    $this->assertSame(DriverStatus::Active, $driver->status);
    $this->assertNull($driver->suspension_reason);
});

it('cancelling the reactivation leaves the driver suspended', function (): void {
    $driver = Driver::factory()->suspended('Documents non conformes')->create();

    Livewire::actingAs(driverFicheUser('direction'))
        ->test(Show::class, ['driver' => $driver])
        ->call('confirmReactivate')
        ->call('cancelReactivate')
        ->assertSet('confirmingReactivation', false);

    $driver->refresh();
    $this->assertSame(DriverStatus::Suspended, $driver->status);
    $this->assertSame('Documents non conformes', $driver->suspension_reason);
});

function driverFicheUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
