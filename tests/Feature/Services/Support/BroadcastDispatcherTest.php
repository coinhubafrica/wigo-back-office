<?php

/**
 * L'envoi d'une diffusion : ce qui part, à qui, et ce qui se passe quand on
 * rejoue.
 */

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastStatus;
use App\Enums\DriverStatus;
use App\Jobs\DispatchBroadcastJob;
use App\Models\Broadcast;
use App\Models\Driver;
use App\Notifications\BroadcastPublished;
use App\Services\Support\BroadcastDispatcher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

it('materialises one recipient per driver', function (): void {
    Notification::fake();
    Driver::factory()->count(5)->create();
    $broadcast = Broadcast::factory()->create(['audience' => BroadcastAudience::All]);

    app(BroadcastDispatcher::class)->dispatch($broadcast);

    expect($broadcast->fresh()->recipients()->count())->toBe(5)
        ->and($broadcast->fresh()->recipients_count)->toBe(5)
        ->and($broadcast->fresh()->status)->toBe(BroadcastStatus::Sent)
        ->and($broadcast->fresh()->sent_at)->not->toBeNull();
});

it('freezes the audience at send time', function (): void {
    // Un filtre rejoué à la lecture ferait disparaître la diffusion du fil
    // d'un conducteur dès que son statut change.
    Notification::fake();
    $drivers = Driver::factory()->count(3)->create(['status' => DriverStatus::Active]);
    $broadcast = Broadcast::factory()->create([
        'audience' => BroadcastAudience::Segment,
        'segment' => ['status' => [DriverStatus::Active->value]],
    ]);

    app(BroadcastDispatcher::class)->dispatch($broadcast);

    $drivers->first()->forceFill(['status' => DriverStatus::Suspended])->save();

    expect($broadcast->fresh()->recipients()->count())->toBe(3);
});

it('does not duplicate recipients when replayed', function (): void {
    // Une reprise après échec ne doit rien ajouter.
    Notification::fake();
    Driver::factory()->count(4)->create();
    $broadcast = Broadcast::factory()->create(['audience' => BroadcastAudience::All]);
    $dispatcher = app(BroadcastDispatcher::class);

    $dispatcher->dispatch($broadcast);
    $dispatcher->dispatch($broadcast->fresh());

    expect($broadcast->fresh()->recipients()->count())->toBe(4);
});

it('does not notify twice when replayed', function (): void {
    // Le point qui compte : rejouer un envoi à moitié fait ne renotifie
    // personne. Cinq mille conducteurs prévenus deux fois, c'est un incident.
    Notification::fake();
    Driver::factory()->count(4)->create();
    $broadcast = Broadcast::factory()->create(['audience' => BroadcastAudience::All]);
    $dispatcher = app(BroadcastDispatcher::class);

    $dispatcher->dispatch($broadcast);
    $dispatcher->dispatch($broadcast->fresh());

    Notification::assertCount(4);
});

it('notifies every recipient', function (): void {
    Notification::fake();
    $drivers = Driver::factory()->count(3)->create();
    $broadcast = Broadcast::factory()->create(['audience' => BroadcastAudience::All]);

    app(BroadcastDispatcher::class)->dispatch($broadcast);

    Notification::assertSentTo($drivers, BroadcastPublished::class);
});

it('writes the notification to the database first', function (): void {
    // Le push n'est qu'un réveil : c'est cette ligne que l'écran
    // « Notifications » lit.
    $driver = Driver::factory()->create();
    $broadcast = Broadcast::factory()->create([
        'audience' => BroadcastAudience::All,
        'title' => 'Maintenance dimanche',
        'body' => "L'application sera indisponible de 2 h à 4 h.",
    ]);

    app(BroadcastDispatcher::class)->dispatch($broadcast);

    $notification = $driver->fresh()->notifications()->sole();
    expect($notification->data['type'])->toBe('broadcast')
        ->and($notification->data['title'])->toBe('Maintenance dimanche')
        ->and($notification->data['broadcast_id'])->toBe($broadcast->id);
});

it('reaches only the segment', function (): void {
    Notification::fake();
    Driver::factory()->count(2)->create(['status' => DriverStatus::Active]);
    Driver::factory()->count(3)->create(['status' => DriverStatus::Suspended]);
    $broadcast = Broadcast::factory()->create([
        'audience' => BroadcastAudience::Segment,
        'segment' => ['status' => [DriverStatus::Active->value]],
    ]);

    app(BroadcastDispatcher::class)->dispatch($broadcast);

    expect($broadcast->fresh()->recipients()->count())->toBe(2);
});

it('reaches only the named driver', function (): void {
    Notification::fake();
    $wanted = Driver::factory()->create();
    Driver::factory()->count(4)->create();
    $broadcast = Broadcast::factory()->create([
        'audience' => BroadcastAudience::Individual,
        'segment' => ['driver_ids' => [$wanted->id]],
    ]);

    app(BroadcastDispatcher::class)->dispatch($broadcast);

    expect($broadcast->fresh()->recipients()->pluck('driver_id')->all())->toBe([$wanted->id]);
});

it('handles a fleet larger than one chunk', function (): void {
    // Les lots font 500 : ce test franchit la limite pour de vrai.
    Notification::fake();
    Driver::factory()->count(520)->create();
    $broadcast = Broadcast::factory()->create(['audience' => BroadcastAudience::All]);

    app(BroadcastDispatcher::class)->dispatch($broadcast);

    expect($broadcast->fresh()->recipients()->count())->toBe(520)
        ->and($broadcast->fresh()->recipients_count)->toBe(520);
});

it('runs the send in the background', function (): void {
    Queue::fake();
    $broadcast = Broadcast::factory()->create();

    DispatchBroadcastJob::dispatch($broadcast->id);

    Queue::assertPushed(DispatchBroadcastJob::class);
});

it('ignores a broadcast deleted before the job ran', function (): void {
    $job = new DispatchBroadcastJob('01m0000000000000000000000');

    $job->handle(app(BroadcastDispatcher::class));

    expect(true)->toBeTrue();
});
