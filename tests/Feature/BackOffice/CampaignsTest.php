<?php

/**
 * Le composeur d'envois sortants. Le module qui peut toucher tout le parc :
 * ce qu'on vérifie ici, c'est surtout ce qui empêche un envoi de partir de
 * travers.
 */

use App\Enums\BackOfficeModule;
use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\DriverStatus;
use App\Jobs\DispatchCampaignJob;
use App\Livewire\Campaigns\Index;
use App\Models\Campaign;
use App\Models\Driver;
use App\Models\Message;
use App\Models\User;
use App\Services\Support\CampaignDispatcher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('lets an authorised user reach the composer', function (): void {
    $this->actingAs(campaignsUser('bonus'))
        ->get(route(BackOfficeModule::Campaigns->route()))
        ->assertOk();
});

it('refuses a user without the permission', function (): void {
    $this->actingAs(campaignsUser('stock'))
        ->get(route(BackOfficeModule::Campaigns->route()))
        ->assertForbidden();
});

it('counts the whole fleet before sending', function (): void {
    // Le nombre affiché avant l'envoi doit être celui qui partira.
    Driver::factory()->count(7)->create();

    Livewire::actingAs(campaignsUser('bonus'))
        ->test(Index::class)
        ->assertViewHas('recipientCount', 7);
});

it('counts only the segment', function (): void {
    Driver::factory()->count(4)->create(['status' => DriverStatus::Active]);
    Driver::factory()->count(3)->create(['status' => DriverStatus::Suspended]);

    Livewire::actingAs(campaignsUser('bonus'))
        ->test(Index::class)
        ->set('audience', CampaignAudience::Segment->value)
        ->call('toggleStatus', DriverStatus::Active->value)
        ->assertViewHas('recipientCount', 4);
});

it('counts the named drivers only', function (): void {
    $drivers = Driver::factory()->count(5)->create();

    Livewire::actingAs(campaignsUser('bonus'))
        ->test(Index::class)
        ->set('audience', CampaignAudience::Individual->value)
        ->call('toggleDriver', $drivers->first()->id)
        ->assertViewHas('recipientCount', 1);
});

it('saves a draft without sending anything', function (): void {
    Queue::fake();

    Livewire::actingAs(campaignsUser('bonus'))
        ->test(Index::class)
        ->call('compose')
        ->set('title', 'Maintenance dimanche')
        ->set('body', "L'application sera indisponible de 2 h à 4 h.")
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Campaign::query()->sole()->status)->toBe(CampaignStatus::Draft);
    Queue::assertNothingPushed();
});

it('asks for confirmation before sending', function (): void {
    Queue::fake();

    Livewire::actingAs(campaignsUser('bonus'))
        ->test(Index::class)
        ->call('compose')
        ->set('title', 'Maintenance dimanche')
        ->set('body', 'Indisponible de 2 h à 4 h.')
        ->assertSet('confirmingSendId', null)
        ->call('confirmSend')
        ->assertSet('confirmingSendId', 'new')
        ->call('send')
        ->assertSet('confirmingSendId', null);

    Queue::assertPushed(DispatchCampaignJob::class);
});

it('sends nothing when the confirmation is cancelled', function (): void {
    Queue::fake();

    Livewire::actingAs(campaignsUser('bonus'))
        ->test(Index::class)
        ->call('compose')
        ->set('title', 'Maintenance dimanche')
        ->set('body', 'Indisponible de 2 h à 4 h.')
        ->call('confirmSend')
        ->call('cancelSend')
        ->call('send');

    Queue::assertNothingPushed();
    expect(Campaign::query()->count())->toBe(0);
});

it('refuses a send without a title or a body', function (): void {
    Livewire::actingAs(campaignsUser('bonus'))
        ->test(Index::class)
        ->call('compose')
        ->call('confirmSend')
        ->assertHasErrors(['title', 'body'])
        ->assertSet('confirmingSendId', null);
});

it('refuses an individual send with nobody named', function (): void {
    // Il ne partirait à personne : mieux vaut le refuser que le laisser
    // passer sans bruit.
    Livewire::actingAs(campaignsUser('bonus'))
        ->test(Index::class)
        ->call('compose')
        ->set('title', 'Message personnel')
        ->set('body', 'Bonjour.')
        ->set('audience', CampaignAudience::Individual->value)
        ->call('confirmSend')
        ->assertHasErrors('driverIds');
});

it('refuses a deeplink that is not a wigo target', function (): void {
    Livewire::actingAs(campaignsUser('bonus'))
        ->test(Index::class)
        ->call('compose')
        ->set('title', 'Maintenance')
        ->set('body', 'Bonjour.')
        ->set('deeplink', 'https://exemple.test')
        ->call('confirmSend')
        ->assertHasErrors('deeplink');
});

it('sends an existing draft from the list', function (): void {
    Queue::fake();
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);

    Livewire::actingAs(campaignsUser('bonus'))
        ->test(Index::class)
        ->call('confirmSend', $campaign->id)
        ->assertSet('confirmingSendId', $campaign->id)
        ->call('send');

    Queue::assertPushed(DispatchCampaignJob::class);
    expect(Campaign::query()->count())->toBe(1);
});

it('records the author of a campaign', function (): void {
    Queue::fake();
    $agent = campaignsUser('bonus');

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('compose')
        ->set('title', 'Maintenance')
        ->set('body', 'Bonjour.')
        ->call('saveDraft');

    expect(Campaign::query()->sole()->created_by_user_id)->toBe($agent->id);
});

it('confirms a draft against its own audience not the composers', function (): void {
    // Le nombre affiché à la confirmation est ce sur quoi l'agent s'engage.
    // Celui du composeur — « tous » par défaut — n'a rien à voir avec le
    // brouillon qu'on renvoie, et se tromperait d'un ordre de grandeur.
    Driver::factory()->count(9)->create(['status' => DriverStatus::Active]);
    Driver::factory()->count(4)->create(['status' => DriverStatus::Suspended]);

    $draft = Campaign::factory()->create([
        'status' => CampaignStatus::Draft,
        'audience' => CampaignAudience::Segment,
        'segment' => ['status' => [DriverStatus::Suspended->value]],
    ]);

    Livewire::actingAs(campaignsUser('bonus'))
        ->test(Index::class)
        ->call('confirmSend', $draft->id)
        ->assertViewHas('confirmingCount', 4);
});

it('lists a sent campaign with its delivery and read counts', function (): void {
    // Le rendu de la liste avec une campagne réellement envoyée : les tests
    // qui ne composent que des brouillons ne touchent jamais ces colonnes.
    Notification::fake();
    Driver::factory()->count(4)->create();
    $campaign = Campaign::factory()->create([
        'audience' => CampaignAudience::All,
        'title' => 'Maintenance dimanche',
    ]);
    app(CampaignDispatcher::class)->dispatch($campaign);

    $campaign->fresh()->messages()->limit(1)->get()
        ->each(fn (Message $m) => $m->forceFill(['read_at' => now()])->save());

    $this->actingAs(campaignsUser('bonus'))
        ->get(route(BackOfficeModule::Campaigns->route()))
        ->assertOk()
        ->assertSee('Maintenance dimanche')
        ->assertSee('25,0 %');
});

it('never uses the native confirm dialog', function (): void {
    $this->actingAs(campaignsUser('bonus'))
        ->get(route(BackOfficeModule::Campaigns->route()))
        ->assertOk()
        ->assertDontSee('wire:confirm');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function campaignsUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create(['is_active' => true, ...$attributes]);
    $user->assignRole($role);

    return $user;
}
