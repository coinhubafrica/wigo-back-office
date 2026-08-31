<?php

/**
 * Le contrat mobile du support : un fil unique, et aucune trace des tickets.
 */

use App\Enums\DriverStatus;
use App\Enums\SupportRequestCategory;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Support\MessageService;
use App\Services\Support\SupportRequestService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('refuses an unauthenticated request', function (): void {
    $this->getJson(route('api.v1.support.conversation'))->assertUnauthorized();
});

it('refuses a token without the mobile ability', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['other']);

    $this->getJson(route('api.v1.support.conversation'))->assertForbidden();
});

it('creates the conversation on first read', function (): void {
    // L'application ne doit pas avoir de cas particulier « pas encore écrit ».
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.support.conversation'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    expect(Conversation::query()->where('driver_id', $driver->id)->count())->toBe(1);
});

it('returns the unread count and the preview', function (): void {
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->create([
        'driver_id' => $driver->id,
        'driver_unread_count' => 3,
        'last_message_preview' => 'Nous regardons votre dossier.',
        'last_message_sender_type' => 'user',
    ]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.support.conversation'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 3)
        ->assertJsonPath('data.last_message_preview', 'Nous regardons votre dossier.')
        ->assertJsonPath('data.last_message_sender_type', 'user');
});

it('never exposes the staff unread counter', function (): void {
    $driver = Driver::factory()->create();
    Conversation::factory()->create(['driver_id' => $driver->id]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.support.conversation'))
        ->assertOk()
        ->assertJsonMissingPath('data.staff_unread_count');
});

it('lists the messages newest first with a cursor', function (): void {
    $driver = Driver::factory()->create();
    $messages = app(MessageService::class);
    foreach (range(1, 25) as $i) {
        $messages->sendFromDriver($driver, "Message {$i}");
    }
    Sanctum::actingAs($driver, ['mobile:*']);

    $response = $this->getJson(route('api.v1.support.messages.index'))
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('data.0.body', 'Message 25');

    expect($response->json('meta.next_cursor'))->not->toBeNull();
});

it('caps the page size at fifty', function (): void {
    $driver = Driver::factory()->create();
    $messages = app(MessageService::class);
    foreach (range(1, 60) as $i) {
        $messages->sendFromDriver($driver, "Message {$i}");
    }
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.support.messages.index', ['per_page' => 200]))
        ->assertOk()
        ->assertJsonCount(50, 'data');
});

it('never publishes the ticket a message belongs to', function (): void {
    // Le ticket est une notion du back-office : le conducteur l'ignore.
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = User::factory()->create();
    app(SupportRequestService::class)->createFromTriage(
        $conversation,
        SupportRequestCategory::Payment,
        $agent,
    );
    Sanctum::actingAs($driver, ['mobile:*']);

    $response = $this->getJson(route('api.v1.support.messages.index'))->assertOk();

    foreach ($response->json('data') as $message) {
        expect($message)->not->toHaveKey('support_request_id');
    }
});

it('publishes a system message with no sender', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Mon solde est faux');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    app(SupportRequestService::class)->createFromTriage(
        $conversation,
        SupportRequestCategory::Payment,
        User::factory()->create(),
    );
    Sanctum::actingAs($driver, ['mobile:*']);

    $response = $this->getJson(route('api.v1.support.messages.index'))->assertOk();

    $system = collect($response->json('data'))->firstWhere('type', 'system');
    expect($system['sender_type'])->toBeNull()
        ->and($system['system_event'])->toBe('request_opened')
        // Rendu côté serveur en plus de l'évènement : une version ancienne de
        // l'application affiche la phrase plutôt que rien.
        ->and($system['body'])->not->toBeEmpty();
});

it('shows a driver only their own messages', function (): void {
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();
    $messages = app(MessageService::class);
    $messages->sendFromDriver($mine, 'À moi');
    $messages->sendFromDriver($other, 'Pas à moi');
    Sanctum::actingAs($mine, ['mobile:*']);

    $response = $this->getJson(route('api.v1.support.messages.index'))->assertOk();

    expect(collect($response->json('data'))->pluck('body')->all())->toBe(['À moi']);
});

it('marks the thread as read', function (): void {
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->create([
        'driver_id' => $driver->id,
        'driver_unread_count' => 4,
    ]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.support.read'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    expect($conversation->fresh()->driver_unread_count)->toBe(0);
});

it('returns the unread count on its own', function (): void {
    $driver = Driver::factory()->create();
    Conversation::factory()->create(['driver_id' => $driver->id, 'driver_unread_count' => 7]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.support.unread'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 7);
});

it('returns zero unread for a driver who never wrote', function (): void {
    // Et sans créer de conversation au passage : la pastille est en lecture
    // seule, elle ne doit rien écrire.
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.support.unread'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    expect(Conversation::query()->count())->toBe(0);
});

it('lets a suspended driver read the support thread', function (): void {
    // Contester sa suspension passe par là : à rebours des autres modules, le
    // support reste ouvert. Le contraste est vérifié ci-dessous.
    $driver = Driver::factory()->create([
        'status' => DriverStatus::Suspended,
        'suspension_reason' => 'Documents expirés',
    ]);
    app(MessageService::class)->sendFromDriver($driver, 'Pourquoi suis-je suspendu ?');
    Sanctum::actingAs($driver->fresh(), ['mobile:*']);

    $this->getJson(route('api.v1.support.conversation'))->assertOk();
    $this->getJson(route('api.v1.support.messages.index'))->assertOk();

    // Le même conducteur ne peut toujours pas commander.
    $this->postJson(route('api.v1.shop.orders.store'), [], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertForbidden();
});

it('serves an attachment through a signed url', function (): void {
    Storage::fake('local');
    $driver = Driver::factory()->create();
    $message = app(MessageService::class)->sendFromDriver($driver, 'Voici le reçu');
    Storage::disk('local')->put('support-attachments/recu.jpg', 'contenu');
    $attachment = MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'disk' => 'local',
        'path' => 'support-attachments/recu.jpg',
    ]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $url = URL::temporarySignedRoute(
        'api.v1.support.attachments.show',
        now()->addHour(),
        ['attachment' => $attachment->id],
    );

    $this->get($url)->assertOk();
});

it('refuses an unsigned attachment url', function (): void {
    $driver = Driver::factory()->create();
    $message = app(MessageService::class)->sendFromDriver($driver, 'Voici le reçu');
    $attachment = MessageAttachment::factory()->create(['message_id' => $message->id]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.support.attachments.show', ['attachment' => $attachment->id]))
        ->assertForbidden();
});

it('refuses an unsigned url before looking the attachment up', function (): void {
    // La signature est vérifiée avant la résolution du modèle : une pièce
    // jointe inexistante ne doit pas répondre 404 à qui n'a pas d'URL signée,
    // sinon l'endpoint devient un oracle d'existence.
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.support.attachments.show', ['attachment' => '01m0000000000000000000000']))
        ->assertForbidden();
});

it('refuses another drivers attachment', function (): void {
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();
    $message = app(MessageService::class)->sendFromDriver($other, 'Mon reçu');
    $attachment = MessageAttachment::factory()->create(['message_id' => $message->id]);
    Sanctum::actingAs($mine, ['mobile:*']);

    $url = URL::temporarySignedRoute(
        'api.v1.support.attachments.show',
        now()->addHour(),
        ['attachment' => $attachment->id],
    );

    $this->get($url)->assertForbidden();
});
