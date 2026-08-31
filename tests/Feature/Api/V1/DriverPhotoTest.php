<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DriverStatus;
use App\Models\Driver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverPhotoTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson(route('api.v1.photo.update'), [
            'photo' => UploadedFile::fake()->image('selfie.jpg', 400, 400),
        ])->assertUnauthorized();
    }

    public function test_it_stores_the_photo_on_the_private_disk_and_updates_the_profile(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->postJson(route('api.v1.photo.update'), [
            'photo' => UploadedFile::fake()->image('selfie.jpg', 400, 400),
        ])
            ->assertOk()
            ->assertJsonPath('message', __('api.driver.photo_updated'));

        $driver->refresh();

        $this->assertStringStartsWith("driver-photos/{$driver->id}/", (string) $driver->photo_url);
        Storage::disk('local')->assertExists($driver->photo_url);
    }

    public function test_it_deletes_the_previous_photo(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->postJson(route('api.v1.photo.update'), [
            'photo' => UploadedFile::fake()->image('first.jpg', 400, 400),
        ])->assertOk();

        $firstPath = $driver->refresh()->photo_url;

        $this->postJson(route('api.v1.photo.update'), [
            'photo' => UploadedFile::fake()->image('second.jpg', 400, 400),
        ])->assertOk();

        $this->assertNotSame($firstPath, $driver->refresh()->photo_url);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($driver->photo_url);
    }

    public function test_a_suspended_driver_may_still_change_their_photo(): void
    {
        $driver = Driver::factory()->create([
            'status' => DriverStatus::Suspended,
            'suspension_reason' => 'Documents expirés',
        ]);
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->postJson(route('api.v1.photo.update'), [
            'photo' => UploadedFile::fake()->image('selfie.jpg', 400, 400),
        ])->assertOk();
    }

    public function test_it_rejects_a_file_that_is_not_an_image(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->postJson(route('api.v1.photo.update'), [
            'photo' => UploadedFile::fake()->create('contrat.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_it_rejects_an_image_that_is_too_small(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->postJson(route('api.v1.photo.update'), [
            'photo' => UploadedFile::fake()->image('vignette.jpg', 100, 100),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', __('api.driver.photo_too_small'));
    }

    public function test_it_rejects_an_image_above_five_megabytes(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->postJson(route('api.v1.photo.update'), [
            'photo' => UploadedFile::fake()->image('grande.jpg', 400, 400)->size(5121),
        ])->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_the_profile_exposes_a_signed_url_that_serves_the_photo(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->postJson(route('api.v1.photo.update'), [
            'photo' => UploadedFile::fake()->image('selfie.jpg', 400, 400),
        ])->assertOk();

        $photoUrl = $this->getJson(route('api.v1.me'))
            ->assertOk()
            ->json('data.photo_url');

        $this->assertIsString($photoUrl);
        $this->get($photoUrl)->assertOk();
    }

    public function test_the_profile_photo_url_is_null_without_a_photo(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->getJson(route('api.v1.me'))
            ->assertOk()
            ->assertJsonPath('data.photo_url', null);
    }

    public function test_an_unsigned_photo_url_is_rejected(): void
    {
        $driver = Driver::factory()->create(['photo_url' => 'driver-photos/x.jpg']);
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->getJson(route('api.v1.photo', ['driver' => $driver->id]))->assertForbidden();
    }

    public function test_a_driver_cannot_download_another_drivers_photo(): void
    {
        $other = Driver::factory()->create(['photo_url' => 'driver-photos/x.jpg']);
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->getJson($this->photoUrl($other))->assertForbidden();
    }

    public function test_a_driver_without_a_photo_returns_not_found(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->getJson($this->photoUrl($driver))->assertNotFound();
    }

    public function test_a_missing_file_returns_not_found(): void
    {
        $driver = Driver::factory()->create(['photo_url' => 'driver-photos/disparue.jpg']);
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->getJson($this->photoUrl($driver))->assertNotFound();
    }

    private function photoUrl(Driver $driver): string
    {
        return URL::temporarySignedRoute(
            'api.v1.photo',
            now()->addMinutes(5),
            ['driver' => $driver->id],
        );
    }
}
