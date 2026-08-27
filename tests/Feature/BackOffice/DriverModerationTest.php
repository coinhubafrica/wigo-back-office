<?php

namespace Tests\Feature\BackOffice;

use App\Enums\DriverPhotoStatus;
use App\Enums\DriverStatus;
use App\Livewire\Drivers\Show;
use App\Models\Driver;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DriverModerationTest extends TestCase
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

    public function test_the_photo_moderation_banner_only_shows_when_pending(): void
    {
        $driver = Driver::factory()->create(['photo_url' => 'https://example.com/photo.jpg', 'photo_status' => DriverPhotoStatus::Pending]);

        Livewire::actingAs($this->user('direction'))
            ->test(Show::class, ['driver' => $driver])
            ->assertSee('Photo de profil en attente de modération');
    }

    public function test_the_photo_moderation_banner_is_hidden_when_not_pending(): void
    {
        $driver = Driver::factory()->create(['photo_status' => null]);

        Livewire::actingAs($this->user('direction'))
            ->test(Show::class, ['driver' => $driver])
            ->assertDontSee('Photo de profil en attente de modération');
    }

    public function test_approving_the_photo_sets_the_status_and_clears_the_banner(): void
    {
        $driver = Driver::factory()->create(['photo_url' => 'https://example.com/photo.jpg', 'photo_status' => DriverPhotoStatus::Pending]);

        Livewire::actingAs($this->user('direction'))
            ->test(Show::class, ['driver' => $driver])
            ->call('approvePhoto')
            ->assertDontSee('Photo de profil en attente de modération');

        $this->assertSame(DriverPhotoStatus::Approved, $driver->fresh()->photo_status);
    }

    public function test_rejecting_the_photo_sets_the_status(): void
    {
        $driver = Driver::factory()->create(['photo_url' => 'https://example.com/photo.jpg', 'photo_status' => DriverPhotoStatus::Pending]);

        Livewire::actingAs($this->user('direction'))
            ->test(Show::class, ['driver' => $driver])
            ->call('rejectPhoto');

        $this->assertSame(DriverPhotoStatus::Rejected, $driver->fresh()->photo_status);
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
            ->call('reactivate');

        $driver->refresh();
        $this->assertSame(DriverStatus::Active, $driver->status);
        $this->assertNull($driver->suspension_reason);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
