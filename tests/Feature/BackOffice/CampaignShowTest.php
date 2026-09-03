<?php

/**
 * Page de détail d'une campagne : ce qui a été écrit, à qui, et qui l'a lu.
 */

use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\DriverStatus;
use App\Jobs\DispatchCampaignJob;
use App\Livewire\Campaigns\Show;
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

it('lets an authorised user open a campaign', function (): void {
    $campaign = Campaign::factory()->create(['title' => 'Maintenance dimanche']);

    $this->actingAs(showUser('bonus'))
        ->get(route('bo.campaigns.show', $campaign))
        ->assertOk()
        ->assertSee('Maintenance dimanche');
});

it('refuses a user without the permission', function (): void {
    $campaign = Campaign::factory()->create();

    $this->actingAs(showUser('stock'))
        ->get(route('bo.campaigns.show', $campaign))
        ->assertForbidden();
});

it('shows the message in full rather than truncated', function (): void {
    // Le corps est ce que le conducteur lira : la page de détail doit le
    // montrer entier, contrairement à la liste.
    $body = str_repeat('Chaque mot compte. ', 40);
    $campaign = Campaign::factory()->create(['body' => $body]);

    $this->actingAs(showUser('bonus'))
        ->get(route('bo.campaigns.show', $campaign))
        ->assertOk()
        ->assertSee(trim($body));
});

it('counts deliveries and reads from the messages', function (): void {
    Notification::fake();
    Driver::factory()->count(4)->create();
    $campaign = Campaign::factory()->create(['audience' => CampaignAudience::All]);
    app(CampaignDispatcher::class)->dispatch($campaign);

    $campaign->fresh()->messages()->limit(1)->get()
        ->each(fn (Message $m) => $m->forceFill(['read_at' => now()])->save());

    Livewire::actingAs(showUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign->fresh()])
        ->assertViewHas('delivered', 4)
        ->assertViewHas('read', 1)
        ->assertViewHas('rate', 25.0);
});

it('shows a dash rather than a zero rate when nothing was delivered', function (): void {
    // 0 % se lirait comme « personne n'a lu », alors que rien n'est parti.
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);

    Livewire::actingAs(showUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->assertViewHas('rate', null);
});

it('estimates what a draft would reach today', function (): void {
    Driver::factory()->count(3)->create(['status' => DriverStatus::Active]);
    Driver::factory()->count(2)->create(['status' => DriverStatus::Suspended]);
    $campaign = Campaign::factory()->create([
        'status' => CampaignStatus::Draft,
        'audience' => CampaignAudience::Segment,
        'segment' => ['status' => [DriverStatus::Active->value]],
    ]);

    Livewire::actingAs(showUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->assertViewHas('pending', 3);
});

it('does not estimate for a campaign already sent', function (): void {
    Notification::fake();
    Driver::factory()->count(2)->create();
    $campaign = Campaign::factory()->create(['audience' => CampaignAudience::All]);
    app(CampaignDispatcher::class)->dispatch($campaign);

    Livewire::actingAs(showUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign->fresh()])
        ->assertViewHas('pending', null);
});

it('spells out the segment rather than showing raw json', function (): void {
    $campaign = Campaign::factory()->create([
        'audience' => CampaignAudience::Segment,
        'segment' => ['status' => [DriverStatus::Active->value], 'has_vehicle' => true],
    ]);

    Livewire::actingAs(showUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->assertViewHas('segmentLabels', ['Actif', 'Avec véhicule'])
        ->assertDontSee('has_vehicle');
});

it('filters the recipients by read state', function (): void {
    Notification::fake();
    Driver::factory()->count(4)->create();
    $campaign = Campaign::factory()->create(['audience' => CampaignAudience::All]);
    app(CampaignDispatcher::class)->dispatch($campaign);
    $campaign->fresh()->messages()->limit(1)->get()
        ->each(fn (Message $m) => $m->forceFill(['read_at' => now()])->save());

    $component = Livewire::actingAs(showUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign->fresh()]);

    expect($component->viewData('recipients'))->toHaveCount(4);

    $component->call('filterBy', 'read');
    expect($component->viewData('recipients'))->toHaveCount(1);

    $component->call('filterBy', 'unread');
    expect($component->viewData('recipients'))->toHaveCount(3);
});

it('freezes the confirmation count when the modal opens', function (): void {
    // Le nombre confirmé ne doit pas bouger entre la lecture et le clic.
    Driver::factory()->count(3)->create();
    $campaign = Campaign::factory()->create([
        'status' => CampaignStatus::Draft,
        'audience' => CampaignAudience::All,
    ]);

    $component = Livewire::actingAs(showUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('confirmSend')
        ->assertSet('confirmingCount', 3);

    Driver::factory()->count(5)->create();

    $component->assertSet('confirmingCount', 3);
});

it('sends a draft from its own page', function (): void {
    Queue::fake();
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);

    Livewire::actingAs(showUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('confirmSend')
        ->assertSet('confirmingSend', true)
        ->assertSeeHtml('wire:target="send"')
        ->call('send')
        ->assertSet('confirmingSend', false);

    Queue::assertPushed(DispatchCampaignJob::class);
});

it('never uses the native confirm dialog', function (): void {
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);

    $this->actingAs(showUser('bonus'))
        ->get(route('bo.campaigns.show', $campaign))
        ->assertOk()
        ->assertDontSee('wire:confirm');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function showUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create(['is_active' => true, ...$attributes]);
    $user->assignRole($role);

    return $user;
}
