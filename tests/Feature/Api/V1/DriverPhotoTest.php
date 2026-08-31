<?php

use App\Enums\DriverStatus;
use App\Models\Driver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Storage::fake('local');
});

it('requires authentication', function (): void {
    $this->postJson(route('api.v1.photo.update'), [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 400, 400),
    ])->assertUnauthorized();
});

it('stores the photo on the private disk and updates the profile', function (): void {
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
});

it('deletes the previous photo', function (): void {
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
});

it('lets a suspended driver still change their photo', function (): void {
    $driver = Driver::factory()->create([
        'status' => DriverStatus::Suspended,
        'suspension_reason' => 'Documents expirés',
    ]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.photo.update'), [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 400, 400),
    ])->assertOk();
});

it('rejects a file that is not an image', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->postJson(route('api.v1.photo.update'), [
        'photo' => UploadedFile::fake()->create('contrat.pdf', 100, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors('photo');
});

it('rejects an image that is too small', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->postJson(route('api.v1.photo.update'), [
        'photo' => UploadedFile::fake()->image('vignette.jpg', 100, 100),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.photo.0', __('api.driver.photo_too_small'));
});

it('rejects an image above five megabytes', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->postJson(route('api.v1.photo.update'), [
        'photo' => UploadedFile::fake()->image('grande.jpg', 400, 400)->size(5121),
    ])->assertUnprocessable()->assertJsonValidationErrors('photo');
});

it('exposes a signed url that serves the photo on the profile', function (): void {
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
});

it('returns a null profile photo url without a photo', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.photo_url', null);
});

it('rejects an unsigned photo url', function (): void {
    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/x.jpg']);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.photo', ['driver' => $driver->id]))->assertForbidden();
});

it('prevents a driver from downloading another drivers photo', function (): void {
    $other = Driver::factory()->create(['photo_url' => 'driver-photos/x.jpg']);
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->getJson(photoUrl($other))->assertForbidden();
});

it('returns not found for a driver without a photo', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(photoUrl($driver))->assertNotFound();
});

it('returns not found for a missing file', function (): void {
    $driver = Driver::factory()->create(['photo_url' => 'driver-photos/disparue.jpg']);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(photoUrl($driver))->assertNotFound();
});

function photoUrl(Driver $driver): string
{
    return URL::temporarySignedRoute(
        'api.v1.photo',
        now()->addMinutes(5),
        ['driver' => $driver->id],
    );
}
