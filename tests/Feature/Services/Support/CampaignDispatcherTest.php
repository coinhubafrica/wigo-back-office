<?php

/**
 * L'envoi d'une campagne : ce qui part, à qui, et ce qui se passe quand on
 * rejoue.
 */

use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\DriverStatus;
use App\Enums\MessageType;
use App\Enums\SystemMessageEvent;
use App\Jobs\DispatchCampaignJob;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Driver;
use App\Notifications\CampaignPublished;
use App\Services\Support\CampaignDispatcher;
use App\Services\Support\MessageService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

it('drops the message into every recipient conversation', function (): void {
    Notification::fake();
    Driver::factory()->count(5)->create();
    $campaign = Campaign::factory()->create(['audience' => CampaignAudience::All]);

    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->fresh()->messages()->count())->toBe(5)
        ->and($campaign->fresh()->recipients_count)->toBe(5)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sent)
        ->and($campaign->fresh()->sent_at)->not->toBeNull();
});

it('freezes the audience at send time', function (): void {
    // Un filtre rejoué à la lecture ferait disparaître la campagne du fil
    // d'un conducteur dès que son statut change.
    Notification::fake();
    $drivers = Driver::factory()->count(3)->create(['status' => DriverStatus::Active]);
    $campaign = Campaign::factory()->create([
        'audience' => CampaignAudience::Segment,
        'segment' => ['status' => [DriverStatus::Active->value]],
    ]);

    app(CampaignDispatcher::class)->dispatch($campaign);

    $drivers->first()->forceFill(['status' => DriverStatus::Suspended])->save();

    expect($campaign->fresh()->messages()->count())->toBe(3);
});

it('does not deliver twice when replayed', function (): void {
    // Une reprise après échec ne doit rien ajouter.
    Notification::fake();
    Driver::factory()->count(4)->create();
    $campaign = Campaign::factory()->create(['audience' => CampaignAudience::All]);
    $dispatcher = app(CampaignDispatcher::class);

    $dispatcher->dispatch($campaign);
    $dispatcher->dispatch($campaign->fresh());

    expect($campaign->fresh()->messages()->count())->toBe(4);
});

it('does not notify twice when replayed', function (): void {
    // Le point qui compte : rejouer un envoi à moitié fait ne renotifie
    // personne. Cinq mille conducteurs prévenus deux fois, c'est un incident.
    Notification::fake();
    Driver::factory()->count(4)->create();
    $campaign = Campaign::factory()->create(['audience' => CampaignAudience::All]);
    $dispatcher = app(CampaignDispatcher::class);

    $dispatcher->dispatch($campaign);
    $dispatcher->dispatch($campaign->fresh());

    Notification::assertCount(4);
});

it('notifies every recipient', function (): void {
    Notification::fake();
    $drivers = Driver::factory()->count(3)->create();
    $campaign = Campaign::factory()->create(['audience' => CampaignAudience::All]);

    app(CampaignDispatcher::class)->dispatch($campaign);

    Notification::assertSentTo($drivers, CampaignPublished::class);
});

it('writes the notification to the database first', function (): void {
    // Le push n'est qu'un réveil : c'est cette ligne que l'écran
    // « Notifications » lit.
    $driver = Driver::factory()->create();
    $campaign = Campaign::factory()->create([
        'audience' => CampaignAudience::All,
        'title' => 'Maintenance dimanche',
        'body' => "L'application sera indisponible de 2 h à 4 h.",
    ]);

    app(CampaignDispatcher::class)->dispatch($campaign);

    $notification = $driver->fresh()->notifications()->sole();
    expect($notification->data['type'])->toBe('campaign')
        ->and($notification->data['title'])->toBe('Maintenance dimanche')
        ->and($notification->data['campaign_id'])->toBe($campaign->id);
});

it('reaches only the segment', function (): void {
    Notification::fake();
    Driver::factory()->count(2)->create(['status' => DriverStatus::Active]);
    Driver::factory()->count(3)->create(['status' => DriverStatus::Suspended]);
    $campaign = Campaign::factory()->create([
        'audience' => CampaignAudience::Segment,
        'segment' => ['status' => [DriverStatus::Active->value]],
    ]);

    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->fresh()->messages()->count())->toBe(2);
});

it('reaches only the named driver', function (): void {
    Notification::fake();
    $wanted = Driver::factory()->create();
    Driver::factory()->count(4)->create();
    $campaign = Campaign::factory()->create([
        'audience' => CampaignAudience::Individual,
        'segment' => ['driver_ids' => [$wanted->id]],
    ]);

    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->fresh()->messages()->with('conversation')->get()->pluck('conversation.driver_id')->all())->toBe([$wanted->id]);
});

it('handles a fleet larger than one chunk', function (): void {
    // Les lots font 500 : ce test franchit la limite pour de vrai.
    Notification::fake();
    Driver::factory()->count(520)->create();
    $campaign = Campaign::factory()->create(['audience' => CampaignAudience::All]);

    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->fresh()->messages()->count())->toBe(520)
        ->and($campaign->fresh()->recipients_count)->toBe(520);
});

it('runs the send in the background', function (): void {
    Queue::fake();
    $campaign = Campaign::factory()->create();

    DispatchCampaignJob::dispatch($campaign->id);

    Queue::assertPushed(DispatchCampaignJob::class);
});

it('ignores a campaign deleted before the job ran', function (): void {
    $job = new DispatchCampaignJob('01m0000000000000000000000');

    $job->handle(app(CampaignDispatcher::class));

    expect(true)->toBeTrue();
});

it('lands in the drivers thread as a system message', function (): void {
    // Le conducteur le lit là où il lit déjà le support.
    Notification::fake();
    $driver = Driver::factory()->create();
    $campaign = Campaign::factory()->create([
        'audience' => CampaignAudience::All,
        'body' => "L'application sera indisponible dimanche.",
    ]);

    app(CampaignDispatcher::class)->dispatch($campaign);

    $message = Conversation::query()->where('driver_id', $driver->id)->sole()->messages()->sole();
    expect($message->type)->toBe(MessageType::System)
        ->and($message->sender_type)->toBeNull()
        ->and($message->system_event)->toBe(SystemMessageEvent::CampaignMessage)
        ->and($message->body)->toBe("L'application sera indisponible dimanche.")
        ->and($message->campaign_id)->toBe($campaign->id);
});

it('lets the driver answer a campaign in the same thread', function (): void {
    // La réponse repart en tri comme n'importe quel sujet nouveau.
    Notification::fake();
    $driver = Driver::factory()->create();
    $campaign = Campaign::factory()->create(['audience' => CampaignAudience::All]);
    app(CampaignDispatcher::class)->dispatch($campaign);

    $reply = app(MessageService::class)->sendFromDriver($driver->fresh(), 'Je ne pourrai pas rouler');

    expect($reply->support_request_id)->toBeNull()
        ->and($reply->isAwaitingTriage())->toBeTrue()
        ->and($reply->conversation_id)->toBe($campaign->messages()->sole()->conversation_id);
});

it('counts the read rate from the delivered messages', function (): void {
    Notification::fake();
    Driver::factory()->count(4)->create();
    $campaign = Campaign::factory()->create(['audience' => CampaignAudience::All]);
    app(CampaignDispatcher::class)->dispatch($campaign);

    $campaign->fresh()->messages()->limit(1)->get()
        ->each(fn ($m) => $m->forceFill(['read_at' => now()])->save());

    expect($campaign->fresh()->readRate())->toBe(25.0);
});
