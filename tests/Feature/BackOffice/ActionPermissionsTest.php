<?php

/**
 * Chaque geste mutant du back-office porte sa permission.
 *
 * L'accès à un module ouvrait tout ce qu'on y faisait : suspendre un
 * conducteur, diffuser une campagne, exécuter un tirage, écraser une clé Wave.
 * Ce fichier est le garde-fou de la séparation — il vérifie qu'un agent qui
 * atteint l'écran sans le droit du geste reçoit un 403, et que le droit seul
 * suffit à l'exercer.
 */

use App\Enums\BackOfficeModule;
use App\Enums\ChallengeStatus;
use App\Enums\ChallengeType;
use App\Enums\DriverStatus;
use App\Enums\Permission;
use App\Livewire\Announcements\Index as AnnouncementsIndex;
use App\Livewire\Campaigns\Show as CampaignsShow;
use App\Livewire\Challenges\Show as ChallengesShow;
use App\Livewire\Drivers\Show as DriversShow;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Challenge;
use App\Models\Driver;
use App\Models\User;
use App\Settings\FleetSettings;
use App\Settings\OtpSettings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Chauffeurs
|--------------------------------------------------------------------------
*/

it('refuses a suspension without drivers.suspend', function (): void {
    $driver = Driver::factory()->create(['status' => DriverStatus::Active]);

    Livewire::actingAs(actionUser([BackOfficeModule::Drivers->permission()]))
        ->test(DriversShow::class, ['driver' => $driver])
        ->set('suspensionReason', 'Comportement')
        ->call('suspend')
        ->assertForbidden();

    expect($driver->fresh()->status)->toBe(DriverStatus::Active);
});

it('suspends and audits with drivers.suspend', function (): void {
    $driver = Driver::factory()->create(['status' => DriverStatus::Active]);

    Livewire::actingAs(actionUser([
        BackOfficeModule::Drivers->permission(),
        Permission::DriversSuspend->value,
    ]))
        ->test(DriversShow::class, ['driver' => $driver])
        ->set('suspensionReason', 'Comportement')
        ->call('suspend')
        ->assertOk();

    expect($driver->fresh()->status)->toBe(DriverStatus::Suspended)
        ->and(AuditLog::query()->where('action', 'driver.suspended')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Annonces — rédiger et publier sont deux décisions
|--------------------------------------------------------------------------
*/

it('refuses publishing an announcement with only announcements.manage', function (): void {
    $announcement = Announcement::factory()->create(['is_active' => false]);

    Livewire::actingAs(actionUser([
        BackOfficeModule::Announcements->permission(),
        Permission::AnnouncementsManage->value,
    ]))
        ->test(AnnouncementsIndex::class)
        ->call('toggle', $announcement->id)
        ->assertForbidden();

    expect($announcement->fresh()->is_active)->toBeFalse();
});

it('publishes and audits with announcements.publish', function (): void {
    $announcement = Announcement::factory()->create(['is_active' => false]);

    Livewire::actingAs(actionUser([
        BackOfficeModule::Announcements->permission(),
        Permission::AnnouncementsPublish->value,
    ]))
        ->test(AnnouncementsIndex::class)
        ->call('toggle', $announcement->id)
        ->assertOk();

    expect($announcement->fresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'announcement.published')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Campagnes — rédiger n'est pas diffuser
|--------------------------------------------------------------------------
*/

it('refuses sending a campaign with only campaigns.manage', function (): void {
    Queue::fake();

    $campaign = Campaign::factory()->create();

    Livewire::actingAs(actionUser([
        BackOfficeModule::Campaigns->permission(),
        Permission::CampaignsManage->value,
    ]))
        ->test(CampaignsShow::class, ['campaign' => $campaign])
        ->call('send')
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('sends and audits with campaigns.send', function (): void {
    Queue::fake();

    $campaign = Campaign::factory()->create();

    Livewire::actingAs(actionUser([
        BackOfficeModule::Campaigns->permission(),
        Permission::CampaignsSend->value,
    ]))
        ->test(CampaignsShow::class, ['campaign' => $campaign])
        ->call('send')
        ->assertOk();

    expect(AuditLog::query()->where('action', 'campaign.sent')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Challenges — le cycle de vie, geste par geste
|--------------------------------------------------------------------------
*/

it('refuses each challenge lifecycle step without its own permission', function (string $method, string $granted): void {
    $challenge = actionChallenge();

    // Le droit accordé est celui d'une *autre* étape : porter l'un ne donne
    // pas l'autre.
    Livewire::actingAs(actionUser([
        BackOfficeModule::Challenges->permission(),
        $granted,
    ]))
        ->test(ChallengesShow::class, ['challenge' => $challenge])
        ->call($method)
        ->assertForbidden();
})->with([
    'clore la période' => ['closePeriod', Permission::ChallengesDraw->value],
    'exécuter le tirage' => ['executeDraw', Permission::ChallengesClosePeriod->value],
    'republier la graine' => ['regenerateSeed', Permission::ChallengesDraw->value],
    'créditer en lot' => ['creditAll', Permission::ChallengesDraw->value],
]);

it('regenerating the seed is reserved and audited', function (): void {
    $challenge = actionChallenge();

    Livewire::actingAs(actionUser([
        BackOfficeModule::Challenges->permission(),
        Permission::ChallengesRegenerateSeed->value,
    ]))
        ->test(ChallengesShow::class, ['challenge' => $challenge])
        ->call('regenerateSeed')
        ->assertOk();

    expect(AuditLog::query()->where('action', 'challenge.seed_regenerated')->exists())->toBeTrue();
});

it('does not grant the seed to the roles that merely run draws', function (): void {
    // Le geste change le hasard après le gel du vivier : il reste à l'arbitre.
    expect(actionRoleUser('bonus')->can(Permission::ChallengesDraw->value))->toBeTrue()
        ->and(actionRoleUser('bonus')->can(Permission::ChallengesRegenerateSeed->value))->toBeFalse()
        ->and(actionRoleUser('direction')->can(Permission::ChallengesRegenerateSeed->value))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Réglages — écraser une clé, pas seulement la lire
|--------------------------------------------------------------------------
*/

it('refuses saving the fleet credentials without settings.manage', function (): void {
    // Le trou que ce découpage ferme : `settings.reveal-secrets` gardait la
    // lecture d'une clé, mais n'importe quel accès au module pouvait la
    // remplacer.
    app(FleetSettings::class)->fill(['api_key' => 'clé-en-place'])->save();

    Livewire::actingAs(actionUser([BackOfficeModule::Settings->permission()]))
        ->test(SettingsIndex::class)
        ->set('fleetBaseUrl', 'https://fleet-api.yango.tech')
        ->set('fleetParkId', 'park-1')
        ->set('fleetApiKey', 'clé-pirate')
        ->call('saveFleet')
        ->assertForbidden();

    expect(app(FleetSettings::class)->api_key)->toBe('clé-en-place');
});

it('saves the settings with settings.manage', function (): void {
    Livewire::actingAs(actionUser([
        BackOfficeModule::Settings->permission(),
        Permission::SettingsManage->value,
    ]))
        ->test(SettingsIndex::class)
        ->set('otpLength', 6)
        ->set('otpTtlMinutes', 7)
        ->set('otpMaxAttempts', 5)
        ->set('otpLockMinutes', 15)
        ->set('otpThrottleMaxSends', 3)
        ->set('otpThrottleDecayMinutes', 10)
        ->set('otpRetentionDays', 30)
        ->call('saveOtp')
        ->assertOk()
        ->assertHasNoErrors();

    expect(app(OtpSettings::class)->ttl_minutes)->toBe(7);
});

it('separates reading a secret from overwriting it', function (): void {
    $reader = actionUser([
        BackOfficeModule::Settings->permission(),
        Permission::SettingsRevealSecrets->value,
    ]);

    expect($reader->can(Permission::SettingsRevealSecrets->value))->toBeTrue()
        ->and($reader->can(Permission::SettingsManage->value))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Agent porteur des seules permissions nommées — jamais d'un rôle : on teste
 * la garde du geste, pas la matrice du seeder.
 *
 * @param  list<string>  $permissions
 */
function actionUser(array $permissions): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo($permissions);

    return $user->fresh();
}

function actionRoleUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * Challenge dont la période est échue : les étapes du cycle de vie sont
 * atteignables.
 */
function actionChallenge(): Challenge
{
    return Challenge::factory()->create([
        'type' => ChallengeType::Raffle,
        'status' => ChallengeStatus::Active,
        'period_start' => now()->subDays(8),
        'period_end' => now()->subDay(),
    ]);
}
