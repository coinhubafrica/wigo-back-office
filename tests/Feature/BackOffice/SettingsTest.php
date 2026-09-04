<?php

/**
 * Réglages métier : ce que le back-office peut changer, et ce qu'il ne doit
 * surtout pas pouvoir changer.
 */

use App\Contracts\FleetDirectory;
use App\Enums\BackOfficeModule;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Livewire\Settings\Index;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Fleet\FakeFleetDirectory;
use App\Settings\FleetSettings;
use App\Settings\OtpSettings;
use App\Settings\RechargeSettings;
use App\Settings\WaveShopSettings;
use App\Settings\WaveTopupSettings;
use App\Support\RevealsSecrets;
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

// ---------------------------------------------------------------- Wave

it('stores each Wave account on its own', function (): void {
    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('waveShopApiKey', 'cle-boutique')
        ->set('waveShopWebhookSecret', 'secret-boutique')
        ->call('saveWaveShop')
        ->assertHasNoErrors()
        ->set('waveTopupApiKey', 'cle-recharge')
        ->set('waveTopupWebhookSecret', 'secret-recharge')
        ->call('saveWaveTopup')
        ->assertHasNoErrors();

    // Les deux comptes ne doivent jamais se mélanger : encaisser une commande
    // sur le compte de recharge fausserait la réconciliation comptable.
    expect(app(WaveShopSettings::class)->api_key)->toBe('cle-boutique')
        ->and(app(WaveShopSettings::class)->webhook_secret)->toBe('secret-boutique')
        ->and(app(WaveTopupSettings::class)->api_key)->toBe('cle-recharge')
        ->and(app(WaveTopupSettings::class)->webhook_secret)->toBe('secret-recharge');
});

it('leaves the other Wave account untouched when saving one', function (): void {
    $topup = app(WaveTopupSettings::class);
    $topup->api_key = 'cle-recharge-en-place';
    $topup->webhook_secret = 'secret-recharge-en-place';
    $topup->save();

    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('waveShopApiKey', 'cle-boutique')
        ->call('saveWaveShop')
        ->assertHasNoErrors();

    // Régler la boutique ne doit pas couper l'encaissement des recharges.
    expect(app(WaveTopupSettings::class)->api_key)->toBe('cle-recharge-en-place')
        ->and(app(WaveTopupSettings::class)->webhook_secret)->toBe('secret-recharge-en-place');
});

it('keeps the stored Wave secrets when the fields are left empty', function (): void {
    $shop = app(WaveShopSettings::class);
    $shop->api_key = 'cle-deja-en-place';
    $shop->webhook_secret = 'secret-deja-en-place';
    $shop->save();

    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->set('waveShopWebhookSecret', 'nouveau-secret')
        ->call('saveWaveShop')
        ->assertHasNoErrors();

    // Enregistrer un secret ne doit pas effacer la clé au passage.
    expect(app(WaveShopSettings::class)->api_key)->toBe('cle-deja-en-place')
        ->and(app(WaveShopSettings::class)->webhook_secret)->toBe('nouveau-secret');
});

it('never sends the stored Wave secrets back to the browser', function (): void {
    $shop = app(WaveShopSettings::class);
    $shop->api_key = 'cle-boutique-secrete';
    $shop->save();

    $topup = app(WaveTopupSettings::class);
    $topup->webhook_secret = 'secret-recharge-tres-secret';
    $topup->save();

    $this->actingAs(settingsUser('admin'))
        ->get(route(BackOfficeModule::Settings->route()))
        ->assertOk()
        ->assertDontSee('cle-boutique-secrete')
        ->assertDontSee('secret-recharge-tres-secret');
});

it('shows a masked preview of each stored secret', function (): void {
    settingsStoreFleetKey('wave_sk_live_ABCDEFGHIJKL4821');

    $shop = app(WaveShopSettings::class);
    $shop->api_key = 'wave_sk_live_MNOPQRSTUVWX7788';
    $shop->save();

    $html = $this->actingAs(settingsUser('admin'))
        ->get(route(BackOfficeModule::Settings->route()))
        ->assertOk()
        ->getContent();

    // L'aperçu nomme la clé en place — préfixe et quatre derniers caractères —
    // sans qu'aucun des deux secrets ne paraisse en entier.
    expect($html)->toContain('wave_sk_live_••••••••••••4821')
        ->toContain('wave_sk_live_••••••••••••7788')
        ->and($html)->not->toContain('ABCDEFGHIJKL4821')
        ->and($html)->not->toContain('MNOPQRSTUVWX7788');
});

it('offers the preview as a placeholder, never as a submitted value', function (): void {
    settingsStoreFleetKey('wave_sk_live_ABCDEFGHIJKL4821');

    $html = $this->actingAs(settingsUser('admin'))
        ->get(route(BackOfficeModule::Settings->route()))
        ->assertOk()
        ->getContent();

    // En `placeholder` l'aperçu est un filigrane : il ne part pas au serveur et
    // ne peut pas être pris pour une saisie au moment d'enregistrer.
    $tag = settingsFieldTag($html, 'field-fleetapikey');

    expect($tag)->toContain('placeholder="wave_sk_live_')
        ->toContain('4821"')
        ->and($tag)->not->toContain('value=');

    // Le champ reste vide côté composant : enregistrer sans y toucher conserve
    // la clé, comme avant l'aperçu.
    Livewire::actingAs(settingsUser('admin'))
        ->test(Index::class)
        ->assertSet('fleetApiKey', '');
});

it('shows no preview for a secret that is not stored', function (): void {
    $response = $this->actingAs(settingsUser('admin'))
        ->get(route(BackOfficeModule::Settings->route()))
        ->assertOk()
        // Le message d'absence porte la conséquence. `assertSee` et non
        // `toContain` : Blade échappe l'apostrophe en `&#039;`.
        ->assertSee(__('backoffice.settings.fleet_api_key_missing'));

    // Aucun filigrane sur le champ.
    expect(settingsFieldTag($response->getContent(), 'field-fleetapikey'))
        ->not->toContain('placeholder=');
});

it('reveals a stored secret in clear to a holder of the permission', function (): void {
    settingsStoreFleetKey('yapi10-E5IuB_zhLWUxL1rE0p46kd45MHZ');

    Livewire::actingAs(settingsRevealer())
        ->test(Index::class)
        ->assertSet('revealedSecrets', [])
        ->call('reveal', 'fleetApiKey')
        ->assertSet('revealedSecrets.fleetApiKey', 'yapi10-E5IuB_zhLWUxL1rE0p46kd45MHZ')
        // Remasquer retire le secret de l'état, donc de la page.
        ->call('conceal', 'fleetApiKey')
        ->assertSet('revealedSecrets', []);
});

it('refuses to reveal without the dedicated permission', function (): void {
    settingsStoreFleetKey('cle-tres-secrete');

    // `admin` administre les Paramètres mais ne relève pas les secrets : régler
    // un plafond et lire la clé d'encaissement sont deux décisions.
    $admin = settingsUser('admin');

    expect($admin->can(RevealsSecrets::PERMISSION))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('reveal', 'fleetApiKey')
        ->assertForbidden();
});

it('refuses to reveal a setting that is not on the whitelist', function (): void {
    // Sans table blanche, `reveal()` deviendrait une lecture arbitraire de la
    // table `settings` pilotée depuis le navigateur.
    Livewire::actingAs(settingsRevealer())
        ->test(Index::class)
        ->call('reveal', 'otpLength')
        ->assertForbidden();
});

it('logs every reveal without writing the secret to the journal', function (): void {
    settingsStoreFleetKey('cle-tres-secrete');

    $user = settingsRevealer();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('reveal', 'fleetApiKey');

    $entry = AuditLog::query()->where('action', 'settings.secret_revealed')->sole();

    expect($entry->user_id)->toBe($user->getKey())
        ->and($entry->context)->toBe(['field' => 'fleetApiKey'])
        // Le journal dit qu'on a relevé, jamais quoi.
        ->and($entry->summary)->not->toContain('cle-tres-secrete')
        ->and(json_encode($entry->context))->not->toContain('cle-tres-secrete');
});

it('logs nothing and reveals nothing when no secret is stored', function (): void {
    Livewire::actingAs(settingsRevealer())
        ->test(Index::class)
        ->call('reveal', 'fleetApiKey')
        ->assertSet('revealedSecrets', []);

    expect(AuditLog::query()->where('action', 'settings.secret_revealed')->count())->toBe(0);
});

it('hides the reveal control from a user without the permission', function (): void {
    settingsStoreFleetKey('cle-tres-secrete');

    $this->actingAs(settingsUser('admin'))
        ->get(route(BackOfficeModule::Settings->route()))
        ->assertOk()
        // Pas de bouton qui proposerait une action toujours refusée.
        ->assertDontSee("reveal('fleetApiKey')", false);

    $this->actingAs(settingsRevealer())
        ->get(route(BackOfficeModule::Settings->route()))
        ->assertOk()
        ->assertSee("reveal('fleetApiKey')", false);
});

it('shows the callback URL of each Wave account', function (): void {
    // Chaque compte doit pointer son propre rappel : c'est le segment d'URL qui
    // désigne le secret à vérifier.
    $this->actingAs(settingsUser('admin'))
        ->get(route(BackOfficeModule::Settings->route()))
        ->assertOk()
        ->assertSee(route('webhooks.wave', ['account' => 'shop']))
        ->assertSee(route('webhooks.wave', ['account' => 'topup']));
});

/**
 * La balise `<input>` portant cet identifiant, pour n'inspecter que ses
 * attributs. Les attributs ne sont pas dans un ordre garanti : on remonte de
 * l'`id` au `<` ouvrant plutôt que d'écrire un motif sur tout le tag.
 */
function settingsFieldTag(string $html, string $id): string
{
    $at = strpos($html, 'id="'.$id.'"');

    if ($at === false) {
        return '';
    }

    $open = strrpos(substr($html, 0, $at), '<');

    return substr($html, (int) $open, (int) strpos($html, '>', $at) - (int) $open + 1);
}

/**
 * Un agent qui porte le droit de relever les secrets en clair.
 */
function settingsRevealer(): User
{
    $user = settingsUser('admin');
    $user->givePermissionTo(RevealsSecrets::PERMISSION);

    return $user->fresh();
}
