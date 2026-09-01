<?php

/**
 * Diffusion temps réel : ce qui part, sur quels canaux, et ce qui n'y figure
 * surtout pas.
 */

use App\Enums\SupportRequestCategory;
use App\Events\Support\MessageRead;
use App\Events\Support\MessageSent;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\Message;
use App\Models\User;
use App\Services\Support\MessageService;
use App\Services\Support\SupportRequestService;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Event;

it('campaigns a driver message', function (): void {
    Event::fake([MessageSent::class]);
    $driver = Driver::factory()->create();

    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e): bool => $e->message->body === 'Mon solde est faux');
});

it('campaigns an agent reply', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = User::factory()->create();
    $request = app(SupportRequestService::class)->createFromTriage(
        $conversation, SupportRequestCategory::Other, $agent,
    );

    Event::fake([MessageSent::class]);
    app(MessageService::class)->sendFromStaff($request->fresh(), $agent, 'Nous regardons');

    Event::assertDispatched(MessageSent::class);
});

it('campaigns a read receipt for each side', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();

    Event::fake([MessageRead::class]);
    app(MessageService::class)->markReadForDriver($conversation);

    Event::assertDispatched(MessageRead::class, fn (MessageRead $e): bool => $e->readerType === 'driver');
});

it('reaches the thread and the queue', function (): void {
    $message = Message::factory()->fromDriver()->create();

    $channels = collect((new MessageSent($message))->broadcastOn())
        ->map(fn ($channel): string => $channel->name)
        ->all();

    expect($channels)->toBe([
        'private-conversation.'.$message->conversation_id,
        'private-support-queue',
    ]);
});

it('carries a preview and never the whole message', function (): void {
    // La trame traverse aussi le canal de la file : un onglet ouvert ne doit
    // pas pouvoir afficher ce qu'il n'a pas le droit de lire.
    $body = str_repeat('secret ', 60);
    $message = Message::factory()->fromDriver()->create(['body' => $body]);

    $payload = (new MessageSent($message))->broadcastWith();

    expect($payload)->not->toHaveKey('body')
        ->and(strlen((string) $payload['preview']))->toBeLessThan(strlen($body))
        ->and($payload)->toHaveKeys(['id', 'conversation_id', 'sender_type', 'sender_name', 'type', 'preview', 'created_at']);
});

it('does not grow the read payload with the number of messages', function (): void {
    $conversation = Conversation::factory()->create();

    $payload = (new MessageRead($conversation, 'user'))->broadcastWith();

    expect(array_keys($payload))->toBe(['conversation_id', 'reader_type', 'read_at']);
});

it('publishes a short stable event name', function (): void {
    // L'application mobile ne se lie pas au nom de classe PHP.
    $message = Message::factory()->fromDriver()->create();

    expect((new MessageSent($message))->broadcastAs())->toBe('message.sent')
        ->and((new MessageRead(Conversation::factory()->create(), 'driver'))->broadcastAs())->toBe('message.read');
});

it('queues the campaign rather than sending it inline', function (): void {
    // Redis rend la mise en file assez rapide, et l'appel HTTP vers Reverb
    // sort du cycle de la requête. Diffuser en ligne l'y remettrait.
    $message = Message::factory()->fromDriver()->create();

    expect(new MessageSent($message))->not->toBeInstanceOf(ShouldBroadcastNow::class);
});
