<?php

/**
 * Les destinataires matérialisés d'un envoi groupé : ce qui est parti, ce qui
 * a échoué, et ce qui se rejoue.
 *
 * Ce qu'on vérifie surtout ici, c'est qu'un envoi ne part jamais deux fois —
 * cinq mille conducteurs notifiés en double, c'est un incident.
 */

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Livewire\Campaigns\Index;
use App\Livewire\Campaigns\Show;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\Message;
use App\Models\User;
use App\Notifications\CampaignPublished;
use App\Services\Support\CampaignDispatcher;
use App\Services\Support\ConversationResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Notification::fake();
});

function campaignRecipientsUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}

/**
 * Fait échouer la remise du conducteur donné, et d'aucun autre : le résolveur
 * de conversation est la plus petite dépendance du dispatcheur, donc le point
 * d'injection le moins intrusif pour provoquer un échec réaliste.
 */
function poisonDriver(Driver $poisoned): void
{
    $real = app(ConversationResolver::class);

    app()->bind(ConversationResolver::class, function () use ($real, $poisoned) {
        return new class($real, $poisoned) extends ConversationResolver
        {
            public function __construct(private ConversationResolver $real, private Driver $poisoned) {}

            public function for(Driver $driver): Conversation
            {
                if ($driver->getKey() === $this->poisoned->getKey()) {
                    throw new RuntimeException('conversation illisible');
                }

                return $this->real->for($driver);
            }
        };
    });
}

it('materialises one row per targeted driver and delivers it', function (): void {
    Driver::factory()->count(4)->create();
    $campaign = Campaign::factory()->create();

    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->targetedCount())->toBe(4)
        ->and($campaign->deliveredCount())->toBe(4)
        ->and($campaign->failedCount())->toBe(0)
        ->and($campaign->fresh()->recipients_count)->toBe(4);

    $campaign->recipients->each(function (CampaignRecipient $recipient): void {
        expect($recipient->status)->toBe(CampaignRecipientStatus::Sent)
            ->and($recipient->message_id)->not->toBeNull()
            ->and($recipient->attempts)->toBe(1);
    });
});

it('never delivers nor notifies twice when a send is replayed', function (): void {
    // Le test qui compte : reprendre un envoi ne doit prévenir personne deux
    // fois. C'est la règle que l'ancienne garde en PHP ne tenait pas.
    Driver::factory()->count(4)->create();
    $campaign = Campaign::factory()->create();

    app(CampaignDispatcher::class)->dispatch($campaign);
    app(CampaignDispatcher::class)->dispatch($campaign);

    expect(Message::query()->where('campaign_id', $campaign->getKey())->count())->toBe(4)
        ->and($campaign->targetedCount())->toBe(4);

    Notification::assertSentTimes(CampaignPublished::class, 4);
});

it('adds a row for a driver who joined after the first send', function (): void {
    Driver::factory()->count(2)->create();
    $campaign = Campaign::factory()->create();

    app(CampaignDispatcher::class)->dispatch($campaign);
    Driver::factory()->create();
    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->targetedCount())->toBe(3)
        ->and($campaign->deliveredCount())->toBe(3);
});

it('records a failure per recipient without stopping the rest of the batch', function (): void {
    $drivers = Driver::factory()->count(4)->create();
    poisonDriver($drivers->first());

    $campaign = Campaign::factory()->create();

    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->deliveredCount())->toBe(3)
        ->and($campaign->failedCount())->toBe(1)
        // Trois conducteurs sur quatre ont bien reçu : la campagne est partie.
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sent);

    $failed = $campaign->recipients()->failed()->sole();

    expect($failed->error)->toContain('conversation illisible')
        ->and($failed->message_id)->toBeNull()
        // Relâché, donc réservable de nouveau : sans quoi le rejeu resterait
        // bloqué à la porte.
        ->and($failed->claimed_at)->toBeNull();
});

it('marks the campaign failed only when nothing at all was delivered', function (): void {
    $driver = Driver::factory()->create();
    poisonDriver($driver);

    $campaign = Campaign::factory()->create();

    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Failed)
        ->and($campaign->deliveredCount())->toBe(0);
});

it('treats a message with a failed push as delivered', function (): void {
    // Le message déposé est le produit ; le push n'est qu'un réveil, et
    // `PushSender` rend `false` sans jamais lever.
    Driver::factory()->create(['fcm_token' => null]);
    $campaign = Campaign::factory()->create();

    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->deliveredCount())->toBe(1)
        ->and($campaign->failedCount())->toBe(0);
});

it('replays a failed recipient', function (): void {
    $drivers = Driver::factory()->count(2)->create();
    poisonDriver($drivers->first());
    $campaign = Campaign::factory()->create();
    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->failedCount())->toBe(1);

    // Le conducteur redevient joignable : c'est ce que rattrape le rejeu.
    app()->forgetInstance(ConversationResolver::class);
    app()->bind(ConversationResolver::class, fn () => new ConversationResolver);

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('confirmReplay', $campaign->recipients()->failed()->sole()->getKey())
        ->call('replay');

    expect($campaign->failedCount())->toBe(0)
        ->and($campaign->deliveredCount())->toBe(2);
});

it('replays every failure at once', function (): void {
    $drivers = Driver::factory()->count(3)->create();
    $campaign = Campaign::factory()->create();

    // Deux échecs posés à la main : plus lisible que d'empoisonner deux fois.
    app(CampaignDispatcher::class)->materialise($campaign);
    $campaign->recipients()->limit(2)->get()->each(
        fn (CampaignRecipient $r) => $r->forceFill([
            'status' => CampaignRecipientStatus::Failed,
            'error' => 'panne passagère',
        ])->save()
    );

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('confirmReplayAll')
        ->call('replayAllFailures');

    expect($campaign->failedCount())->toBe(0)
        ->and($campaign->deliveredCount())->toBe(3);
});

it('refuses to replay a delivery that already succeeded', function (): void {
    Driver::factory()->create();
    $campaign = Campaign::factory()->create();
    app(CampaignDispatcher::class)->dispatch($campaign);

    $delivered = $campaign->recipients()->sole();

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('confirmReplay', $delivered->getKey())
        ->call('replay');

    expect(Message::query()->where('campaign_id', $campaign->getKey())->count())->toBe(1);
});

it('refuses a replay without the send permission', function (): void {
    Driver::factory()->create();
    $campaign = Campaign::factory()->create();
    app(CampaignDispatcher::class)->materialise($campaign);
    $campaign->recipients()->sole()->forceFill(['status' => CampaignRecipientStatus::Failed])->save();

    Livewire::actingAs(campaignRecipientsUser('gestionnaire'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('confirmReplay', $campaign->recipients()->sole()->getKey())
        ->call('replay')
        ->assertForbidden();
});

it('shows the replay confirmation on a campaign already sent', function (): void {
    // Le dialogue vivait dans le bloc « brouillon » : il ne s'affichait donc
    // jamais pour un envoi parti — soit le seul cas où l'on rejoue.
    $drivers = Driver::factory()->count(2)->create();
    poisonDriver($drivers->first());
    $campaign = Campaign::factory()->create();
    app(CampaignDispatcher::class)->dispatch($campaign);

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('confirmReplay', $campaign->recipients()->failed()->sole()->getKey())
        ->assertSee(__('backoffice.campaigns.confirm_replay_title'));
});

it('shows the bulk replay confirmation on a campaign already sent', function (): void {
    $drivers = Driver::factory()->count(2)->create();
    poisonDriver($drivers->first());
    $campaign = Campaign::factory()->create();
    app(CampaignDispatcher::class)->dispatch($campaign);

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('confirmReplayAll')
        ->assertSee(__('backoffice.campaigns.confirm_replay_all_title'));
});

it('lists the audience of a draft before it is ever sent', function (): void {
    // Vérifier *qui* est visé, et pas seulement combien, est la dernière
    // chance de repérer une cible trop large avant d'atteindre tout le parc.
    Driver::factory()->count(3)->create(['last_name' => 'TRAORE']);
    $campaign = Campaign::factory()->create();

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->assertViewHas('recipients', fn ($rows): bool => $rows->total() === 3)
        ->assertSee('TRAORE');

    // Rien n'est matérialisé pour autant : l'audience est une projection.
    expect($campaign->targetedCount())->toBe(0);
});

it('keeps showing materialised recipients once the campaign is sent', function (): void {
    Driver::factory()->count(2)->create();
    $campaign = Campaign::factory()->create();
    app(CampaignDispatcher::class)->dispatch($campaign);

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->assertViewHas('recipients', fn ($rows): bool => $rows->total() === 2
            && $rows->first() instanceof CampaignRecipient);
});

it('sends a draft back to the composer for editing', function (): void {
    $campaign = Campaign::factory()->create();

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('edit')
        ->assertRedirect(route('bo.campaigns', ['brouillon' => $campaign->getKey()]));
});

it('refuses to edit a campaign already sent', function (): void {
    $campaign = Campaign::factory()->sent()->create();

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('edit')
        ->assertNoRedirect();
});

it('opens the composer on the draft named in the url', function (): void {
    $campaign = Campaign::factory()->create(['title' => 'Maintenance dimanche']);

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Index::class, ['editing' => $campaign->getKey()])
        ->assertSet('editingId', $campaign->getKey())
        ->assertSet('title', 'Maintenance dimanche')
        ->assertSet('composerOpen', true);
});

it('filters the recipients by failure', function (): void {
    $drivers = Driver::factory()->count(3)->create();
    poisonDriver($drivers->first());
    $campaign = Campaign::factory()->create();
    app(CampaignDispatcher::class)->dispatch($campaign);

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('filterBy', 'failed')
        ->assertViewHas('recipients', fn ($rows): bool => $rows->total() === 1);
});

it('never counts a failed delivery as unread', function (): void {
    // Un échec n'a pas de message : le compter en « non lu » mélangerait
    // « pas encore ouvert » et « jamais reçu ».
    $drivers = Driver::factory()->count(3)->create();
    poisonDriver($drivers->first());
    $campaign = Campaign::factory()->create();
    app(CampaignDispatcher::class)->dispatch($campaign);

    Livewire::actingAs(campaignRecipientsUser('bonus'))
        ->test(Show::class, ['campaign' => $campaign])
        ->call('filterBy', 'unread')
        ->assertViewHas('recipients', fn ($rows): bool => $rows->total() === 2);
});
