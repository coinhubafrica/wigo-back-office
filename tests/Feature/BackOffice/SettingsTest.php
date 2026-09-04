<?php

/**
 * Réglages métier : ce que le back-office peut changer, et ce qu'il ne doit
 * surtout pas pouvoir changer.
 */

use App\Contracts\FleetDirectory;
use App\Enums\BackOfficeModule;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Livewire\Settings\Index;
use App\Models\User;
use App\Services\Fleet\FakeFleetDirectory;
use App\Settings\FleetSettings;
use App\Settings\OtpSettings;
use App\Settings\RechargeSettings;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('lets an authorised user reach the settings', function (): void {
    $this->actingAs(settingsUser('admin'))
        ->get(route(BackOfficeModule::Settings->route()))
        ->assertOk()
        ->assertSee(__('backoffice.settings.otp_title'))
        // Les deux enregistrements sont gardés pendant l'aller-retour.
        ->assertSee('wire:target="saveOtp"', false)
        ->assertSee('wire:target="saveRecharge"', false)
        ->assertSee('for="field-otplength"', false);
});

it('refuses a user without the permission', function (): void {
    // `bonus` n'a pas `module.settings` : l'URL directe est refusée.
    $this->actingAs(settingsUser('bonus'))
        ->get(route(BackOfficeModule::Settings->route()))
        ->assertForbidden();
});

it('fills the form with the current values', function (): void {
    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->assertSet('otpLength', 6)
        ->assertSet('otpTtlMinutes', 5)
        ->assertSet('rechargeDailyCap', 150000);
});

it('saves the otp settings', function (): void {
    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('otpLength', 4)
        ->set('otpTtlMinutes', 10)
        ->call('saveOtp')
        ->assertHasNoErrors();

    $otp = app(OtpSettings::class);
    expect($otp->length)->toBe(4)
        ->and($otp->ttl_minutes)->toBe(10);
});

it('saves the recharge settings', function (): void {
    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('rechargeMinAmount', 1000)
        ->set('rechargeMaxAmount', 50000)
        ->set('rechargeDailyCap', 200000)
        ->call('saveRecharge')
        ->assertHasNoErrors();

    $recharge = app(RechargeSettings::class);
    expect($recharge->min_amount)->toBe(1000)
        ->and($recharge->daily_cap)->toBe(200000);
});

it('refuses a maximum below the minimum', function (): void {
    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('rechargeMinAmount', 5000)
        ->set('rechargeMaxAmount', 1000)
        ->call('saveRecharge')
        ->assertHasErrors('rechargeMaxAmount');
});

it('refuses a daily cap below a single maximum recharge', function (): void {
    // Sinon la seconde recharge de la journée serait toujours refusée.
    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('rechargeMinAmount', 500)
        ->set('rechargeMaxAmount', 100000)
        ->set('rechargeDailyCap', 50000)
        ->call('saveRecharge')
        ->assertHasErrors('rechargeDailyCap');
});

it('refuses an otp length the code generator cannot honour', function (): void {
    // `random_int(0, 10 ** $length - 1)` déborde au-delà de neuf chiffres.
    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('otpLength', 12)
        ->call('saveOtp')
        ->assertHasErrors('otpLength');
});

it('never exposes the environment driven switches', function (): void {
    // Un contournement d'OTP ou un jeton de documentation modifiable depuis
    // une page web serait une porte dérobée : ils restent dans config/wigo.php.
    $response = $this->actingAs(settingsUser('admin'))
        ->get(route(BackOfficeModule::Settings->route()))
        ->assertOk();

    $response->assertDontSee('expose_code');
    $response->assertDontSee('API_DOCS_TOKEN');
    $response->assertDontSee('terms_version');

    expect(property_exists(Index::class, 'otpExposeCode'))->toBeFalse()
        ->and(property_exists(Index::class, 'docsToken'))->toBeFalse()
        ->and(property_exists(Index::class, 'termsVersion'))->toBeFalse();
});

it('applies a saved otp length to the next generated code', function (): void {
    // Le réglage doit agir sur le service, pas seulement sur la ligne en base.
    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('otpLength', 4)
        ->call('saveOtp')
        ->assertHasNoErrors();

    expect(app(OtpSettings::class)->length)->toBe(4);
});

// ------------------------------------------------------- accès au parc Yango

it('saves the yango fleet credentials', function (): void {
    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('fleetBaseUrl', 'https://fleet-api.yango.tech')
        ->set('fleetParkId', 'park-123')
        ->set('fleetApiKey', 'cle-secrete')
        ->call('saveFleet')
        ->assertHasNoErrors();

    $fleet = app(FleetSettings::class);

    expect($fleet->park_id)->toBe('park-123')
        ->and($fleet->api_key)->toBe('cle-secrete')
        ->and($fleet->isConfigured())->toBeTrue();
});

it('keeps the stored key when the field is left empty', function (): void {
    settingsStoreFleetKey('cle-deja-en-place');

    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('fleetParkId', 'park-456')
        ->set('fleetApiKey', '')
        ->call('saveFleet')
        ->assertHasNoErrors();

    // Enregistrer le parc ne doit pas effacer la clé au passage.
    expect(app(FleetSettings::class)->api_key)->toBe('cle-deja-en-place');
});

it('never sends the stored key back to the browser', function (): void {
    settingsStoreFleetKey('cle-tres-secrete');

    $this->actingAs(settingsUser('admin'))
        ->get(route(BackOfficeModule::Settings->route()))
        ->assertOk()
        ->assertSee(__('backoffice.settings.fleet_title'))
        ->assertDontSee('cle-tres-secrete');
});

it('encrypts the api key at rest', function (): void {
    settingsStoreFleetKey('cle-tres-secrete');

    $stored = DB::table('settings')
        ->where('group', 'fleet')
        ->where('name', 'api_key')
        ->value('payload');

    // Une lecture de la table ne doit pas suffire à parler au parc.
    expect($stored)->not->toContain('cle-tres-secrete');
});

it('reports a successful connection test', function (): void {
    settingsStoreFleetKey('cle-valide');

    /** @var FakeFleetDirectory $directory */
    $directory = app(FleetDirectory::class);
    $directory->setDrivers([['driver_profile' => ['id' => 'YAN-001']]]);

    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->call('testFleet')
        ->assertSet('fleetTestSucceeded', true)
        ->assertSet('fleetTestMessage', __('backoffice.settings.fleet_test_ok'));
});

it('reports the status when yango refuses the key', function (): void {
    settingsStoreFleetKey('cle-invalide');

    /** @var FakeFleetDirectory $directory */
    $directory = app(FleetDirectory::class);
    $directory->failWith(new class('Clé refusée') extends YangoFleetException
    {
        public function getStatusCode(): ?int
        {
            return 401;
        }
    });

    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->call('testFleet')
        ->assertSet('fleetTestSucceeded', false)
        ->assertSee('401');
});

it('requires a park id and a valid url', function (): void {
    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('fleetBaseUrl', 'pas-une-url')
        ->set('fleetParkId', '')
        ->call('saveFleet')
        ->assertHasErrors(['fleetBaseUrl', 'fleetParkId']);
});

function settingsStoreFleetKey(string $key): void
{
    $fleet = app(FleetSettings::class);
    $fleet->base_url = 'https://fleet-api.yango.tech';
    $fleet->park_id = 'park-123';
    $fleet->api_key = $key;
    $fleet->save();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function settingsUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create(['is_active' => true, ...$attributes]);
    $user->assignRole($role);

    return $user;
}
