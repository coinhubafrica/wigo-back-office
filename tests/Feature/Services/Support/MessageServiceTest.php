<?php

/**
 * Compteurs, rattachement au ticket et lectures : les invariants du fil.
 */

use App\Enums\MessageType;
use App\Enums\SupportRequestStatus;
use App\Enums\SystemMessageEvent;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Support\MessageService;

it('opens the conversation on a driver first message', function (): void {
    $driver = Driver::factory()->create();

    $message = app(MessageService::class)->sendFromDriver($driver, 'Bonjour');

    expect(Conversation::query()->where('driver_id', $driver->id)->count())->toBe(1)
        ->and($message->sender_type)->toBe('driver')
        ->and($message->sender_id)->toBe($driver->id)
        ->and($message->type)->toBe(MessageType::Text);
});

it('reuses the one conversation a driver already has', function (): void {
    $driver = Driver::factory()->create();
    $service = app(MessageService::class);

    $service->sendFromDriver($driver, 'Premier');
    $service->sendFromDriver($driver, 'Second');

    expect(Conversation::query()->where('driver_id', $driver->id)->count())->toBe(1);
});

it('leaves a message untriaged when no request is live', function (): void {
    $driver = Driver::factory()->create();

    $message = app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');

    expect($message->support_request_id)->toBeNull()
        ->and($message->triaged_at)->toBeNull()
        ->and($message->isAwaitingTriage())->toBeTrue();
});

it('attaches a message to the live request instead of triaging it again', function (): void {
    // Le tri ne se déclenche que sur un sujet nouveau, jamais sur une réponse :
    // c'est ce qui garde la file « À trier » courte.
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->create(['driver_id' => $driver->id]);
    $request = SupportRequest::factory()->forConversation($conversation)->create([
        'status' => SupportRequestStatus::Open,
    ]);

    $message = app(MessageService::class)->sendFromDriver($driver, 'Une précision');

    expect($message->support_request_id)->toBe($request->id)
        ->and($message->isAwaitingTriage())->toBeFalse();
});

it('does not attach a message to a resolved request', function (): void {
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->create(['driver_id' => $driver->id]);
    SupportRequest::factory()->forConversation($conversation)->resolved()->create();

    $message = app(MessageService::class)->sendFromDriver($driver, 'Autre chose');

    expect($message->support_request_id)->toBeNull()
        ->and($message->isAwaitingTriage())->toBeTrue();
});

it('raises the staff counter and clears the driver one when the driver writes', function (): void {
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->create([
        'driver_id' => $driver->id,
        'driver_unread_count' => 3,
    ]);
    $request = SupportRequest::factory()->forConversation($conversation)->create([
        'status' => SupportRequestStatus::Open,
        'staff_unread_count' => 0,
    ]);

    app(MessageService::class)->sendFromDriver($driver, 'Bonjour');

    // Écrire vaut lecture : l'expéditeur ne peut pas accumuler du non-lu.
    expect($conversation->fresh()->driver_unread_count)->toBe(0)
        ->and($request->fresh()->staff_unread_count)->toBe(1);
});

it('raises the driver counter and clears the staff one when an agent replies', function (): void {
    $conversation = Conversation::factory()->create(['driver_unread_count' => 0]);
    $request = SupportRequest::factory()->forConversation($conversation)->create([
        'staff_unread_count' => 4,
    ]);
    $agent = User::factory()->create();

    app(MessageService::class)->sendFromStaff($request, $agent, 'Nous regardons');

    expect($conversation->fresh()->driver_unread_count)->toBe(1)
        ->and($request->fresh()->staff_unread_count)->toBe(0);
});

it('stamps the first response only once', function (): void {
    $conversation = Conversation::factory()->create();
    $request = SupportRequest::factory()->forConversation($conversation)->create([
        'first_response_at' => null,
    ]);
    $agent = User::factory()->create();
    $service = app(MessageService::class);

    $service->sendFromStaff($request, $agent, 'Première réponse');
    $first = $request->fresh()->first_response_at;

    $service->sendFromStaff($request->fresh(), $agent, 'Deuxième réponse');

    expect($request->fresh()->first_response_at->toDateTimeString())
        ->toBe($first->toDateTimeString());
});

it('copies the preview onto the conversation', function (): void {
    // La liste et le badge ne doivent jamais avoir à ouvrir `messages`.
    $driver = Driver::factory()->create();

    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');

    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    expect($conversation->last_message_preview)->toBe('Mon solde est faux')
        ->and($conversation->last_message_sender_type)->toBe('driver')
        ->and($conversation->last_message_at)->not->toBeNull();
});

it('writes a system message with no sender at all', function (): void {
    $conversation = Conversation::factory()->create();

    $message = app(MessageService::class)->writeSystemMessage(
        $conversation,
        SystemMessageEvent::RequestResolved,
    );

    expect($message->sender_type)->toBeNull()
        ->and($message->sender_id)->toBeNull()
        ->and($message->isSystem())->toBeTrue()
        ->and($message->type)->toBe(MessageType::System)
        // Le corps est rendu côté serveur en plus de l'évènement, pour qu'une
        // version ancienne de l'application affiche quelque chose.
        ->and($message->body)->toBe('Votre demande a été traitée.');
});

it('marks everything but the drivers own messages as read for the driver', function (): void {
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->create(['driver_id' => $driver->id]);
    $request = SupportRequest::factory()->forConversation($conversation)->create();
    $agent = User::factory()->create();
    $service = app(MessageService::class);

    $service->sendFromStaff($request, $agent, "Réponse de l'agent");
    $service->writeSystemMessage($conversation, SystemMessageEvent::RequestResolved);
    $driverMessage = $service->sendFromDriver($driver, 'Merci');

    $service->markReadForDriver($conversation->fresh());

    expect($conversation->fresh()->driver_unread_count)->toBe(0)
        ->and($conversation->messages()->whereNull('read_at')->count())->toBe(1)
        ->and($driverMessage->fresh()->read_at)->toBeNull();
});

it('marks only the driver messages as read for staff', function (): void {
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->create(['driver_id' => $driver->id]);
    $request = SupportRequest::factory()->forConversation($conversation)->create([
        'status' => SupportRequestStatus::Open,
    ]);
    $agent = User::factory()->create();
    $service = app(MessageService::class);

    $service->sendFromDriver($driver, 'Une question');
    $staffMessage = $service->sendFromStaff($request->fresh(), $agent, 'Une réponse');

    $service->markReadForStaff($request->fresh());

    expect($request->fresh()->staff_unread_count)->toBe(0)
        ->and($staffMessage->fresh()->read_at)->toBeNull()
        ->and($request->messages()->where('sender_type', 'driver')->whereNull('read_at')->count())->toBe(0);
});
