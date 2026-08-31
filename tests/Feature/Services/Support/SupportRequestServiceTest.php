<?php

/**
 * Le tri : ce qui devient un ticket, ce qui est écarté, et ce que le
 * conducteur n'en voit jamais.
 */

use App\Enums\SupportRequestCategory;
use App\Enums\SupportRequestPriority;
use App\Enums\SupportRequestStatus;
use App\Enums\SystemMessageEvent;
use App\Models\Broadcast;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Support\MessageService;
use App\Services\Support\SupportRequestService;

it('attaches every untriaged message to the new request', function (): void {
    $driver = Driver::factory()->create();
    $messages = app(MessageService::class);
    $messages->sendFromDriver($driver, 'Mon solde est faux');
    $messages->sendFromDriver($driver, "J'ai payé hier");
    $conversation = conversationOf($driver);

    $request = app(SupportRequestService::class)->createFromTriage(
        $conversation,
        SupportRequestCategory::Payment,
        User::factory()->create(),
    );

    expect($request->messages()->whereNotNull('support_request_id')->count())->toBeGreaterThanOrEqual(2)
        ->and($conversation->messages()->whereNull('support_request_id')->whereNull('triaged_at')->count())->toBe(0);
});

it('derives the priority from the category at triage', function (): void {
    // L'agent choisit la catégorie, jamais la priorité.
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');
    $conversation = conversationOf($driver);

    $request = app(SupportRequestService::class)->createFromTriage(
        $conversation,
        SupportRequestCategory::Payment,
        User::factory()->create(),
    );

    expect($request->priority)->toBe(SupportRequestPriority::High)
        ->and($request->sla_first_response_due)->not->toBeNull()
        ->and($request->sla_resolution_due)->not->toBeNull();
});

it('proposes a subject taken from the first untriaged message', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');
    $conversation = conversationOf($driver);

    $request = app(SupportRequestService::class)->createFromTriage(
        $conversation,
        SupportRequestCategory::Payment,
        User::factory()->create(),
    );

    expect($request->subject)->toBe('Mon solde est faux');
});

it('announces the opening to the driver', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');
    $conversation = conversationOf($driver);

    app(SupportRequestService::class)->createFromTriage(
        $conversation,
        SupportRequestCategory::Payment,
        User::factory()->create(),
    );

    expect($conversation->messages()->where('system_event', SystemMessageEvent::RequestOpened->value)->count())->toBe(1);
});

it('allocates a readable number per request', function (): void {
    $agent = User::factory()->create();
    $service = app(SupportRequestService::class);
    $messages = app(MessageService::class);

    $numbers = collect(range(1, 3))->map(function () use ($messages, $service, $agent): int {
        $driver = Driver::factory()->create();
        $messages->sendFromDriver($driver, 'Bonjour');
        $conversation = conversationOf($driver);

        return $service->createFromTriage($conversation, SupportRequestCategory::Other, $agent)->number;
    });

    expect($numbers->unique())->toHaveCount(3);
});

it('dismisses untriaged messages without opening a request', function (): void {
    // Le « merci ! » qui n'appelle aucun travail ne doit pas peupler la file.
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Merci beaucoup !');
    $conversation = conversationOf($driver);
    $agent = User::factory()->create();

    $dismissed = app(SupportRequestService::class)->dismissUntriaged($conversation, $agent);

    expect($dismissed)->toBe(1)
        ->and(SupportRequest::query()->count())->toBe(0)
        ->and($conversation->messages()->whereNull('support_request_id')->whereNull('triaged_at')->count())->toBe(0)
        ->and($conversation->messages()->whereNotNull('triaged_at')->first()->triaged_by_user_id)->toBe($agent->id);
});

it('keeps a dismissed message in the drivers thread', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Merci beaucoup !');
    $conversation = conversationOf($driver);

    app(SupportRequestService::class)->dismissUntriaged($conversation, User::factory()->create());

    expect($conversation->messages()->count())->toBe(1);
});

it('recomputes the deadlines when an agent recategorises', function (): void {
    $conversation = Conversation::factory()->create();
    $request = SupportRequest::factory()->forConversation($conversation)->create([
        'category' => SupportRequestCategory::Other,
        'priority' => SupportRequestPriority::Low,
    ]);

    app(SupportRequestService::class)->recategorise($request, SupportRequestCategory::Payment);

    expect($request->fresh()->priority)->toBe(SupportRequestPriority::High)
        ->and($request->fresh()->recategorised_at)->not->toBeNull();
});

it('does not end the drivers thread when a request is resolved', function (): void {
    // Côté mobile il n'y a qu'un fil : le ticket est une notion du back-office.
    $driver = Driver::factory()->create();
    $messages = app(MessageService::class);
    $messages->sendFromDriver($driver, 'Mon solde est faux');
    $conversation = conversationOf($driver);
    $agent = User::factory()->create();
    $service = app(SupportRequestService::class);

    $request = $service->createFromTriage($conversation, SupportRequestCategory::Payment, $agent);
    $service->resolve($request);

    // Un nouveau message rouvre un tri, pas le ticket clos.
    $later = $messages->sendFromDriver($driver->fresh(), 'Autre chose');

    expect($request->fresh()->status)->toBe(SupportRequestStatus::Resolved)
        ->and($later->conversation_id)->toBe($conversation->id)
        ->and($later->support_request_id)->toBeNull()
        ->and($later->isAwaitingTriage())->toBeTrue();
});

it('records the broadcast a request was opened from', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Je réponds à votre message');
    $conversation = conversationOf($driver);
    $broadcast = Broadcast::factory()->create();

    $request = app(SupportRequestService::class)->createFromTriage(
        $conversation,
        SupportRequestCategory::Other,
        User::factory()->create(),
        fromBroadcast: $broadcast,
    );

    expect($request->opened_from_broadcast_id)->toBe($broadcast->id);
});

it('assigns a request and tells the driver', function (): void {
    $conversation = Conversation::factory()->create();
    $request = SupportRequest::factory()->forConversation($conversation)->create();
    $agent = User::factory()->create();

    app(SupportRequestService::class)->assign($request, $agent);

    expect($request->fresh()->assigned_user_id)->toBe($agent->id)
        ->and($conversation->messages()->where('system_event', SystemMessageEvent::RequestAssigned->value)->count())->toBe(1);
});

it('reopens a resolved request', function (): void {
    $conversation = Conversation::factory()->create();
    $request = SupportRequest::factory()->forConversation($conversation)->resolved()->create();

    app(SupportRequestService::class)->reopen($request);

    expect($request->fresh()->status)->toBe(SupportRequestStatus::Open)
        ->and($request->fresh()->resolved_at)->toBeNull();
});

function conversationOf(Driver $driver): Conversation
{
    return Conversation::query()->where('driver_id', $driver->getKey())->sole();
}
