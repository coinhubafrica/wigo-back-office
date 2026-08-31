<?php

/**
 * Écriture mobile : envoyer un message, déposer une pièce jointe, et les
 * garde-fous qui vont avec.
 */

use App\Contracts\PushSender;
use App\Enums\DriverStatus;
use App\Enums\SupportRequestCategory;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Fcm\LogPushSender;
use App\Services\Support\MessageService;
use App\Services\Support\SupportRequestService;
use App\Settings\SupportSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * En-tête d'idempotence. Nommée pour ce fichier : les fonctions déclarées dans
 * un test Pest sont globales, et `WalletControllerTest` porte déjà son propre
 * `idempotent()`.
 *
 * @return array<string, string>
 */
function supportIdempotent(): array
{
    return ['Idempotency-Key' => (string) Str::uuid()];
}

it('sends a message and opens the conversation', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.support.messages.store'), ['body' => 'Mon solde est faux'], supportIdempotent())
        ->assertCreated()
        ->assertJsonPath('data.sender_type', 'driver')
        ->assertJsonPath('data.body', 'Mon solde est faux');

    expect(Conversation::query()->where('driver_id', $driver->id)->count())->toBe(1);
});

it('requires an idempotency key', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.support.messages.store'), ['body' => 'Bonjour'])
        ->assertStatus(422);
});

it('creates one message when the same key is replayed', function (): void {
    // Un renvoi après coupure réseau ne doit pas poster deux fois.
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);
    $headers = supportIdempotent();

    $first = $this->postJson(route('api.v1.support.messages.store'), ['body' => 'Bonjour'], $headers)
        ->assertCreated();
    $second = $this->postJson(route('api.v1.support.messages.store'), ['body' => 'Bonjour'], $headers)
        ->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Conversation::query()->sole()->messages()->count())->toBe(1);
});

it('refuses an empty message with no attachment', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.support.messages.store'), [], supportIdempotent())
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');
});

it('attaches the message to the live ticket', function (): void {
    $driver = Driver::factory()->create();
    app(MessageService::class)->sendFromDriver($driver, 'Première demande');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $request = app(SupportRequestService::class)->createFromTriage(
        $conversation,
        SupportRequestCategory::Payment,
        User::factory()->create(),
    );
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.support.messages.store'), ['body' => 'Une précision'], supportIdempotent())
        ->assertCreated();

    expect($request->fresh()->messages()->where('body', 'Une précision')->exists())->toBeTrue();
});

it('lets a suspended driver write to support', function (): void {
    // Contester sa suspension passe par là. Le contraste avec la boutique est
    // vérifié dans le même test pour que l'écart soit visible.
    $driver = Driver::factory()->create([
        'status' => DriverStatus::Suspended,
        'suspension_reason' => 'Documents expirés',
    ]);
    Sanctum::actingAs($driver->fresh(), ['mobile:*']);

    $this->postJson(route('api.v1.support.messages.store'), ['body' => 'Je conteste'], supportIdempotent())
        ->assertCreated();

    $this->postJson(route('api.v1.shop.orders.store'), [], supportIdempotent())
        ->assertForbidden();
});

it('can close the door on suspended drivers from the settings', function (): void {
    $settings = app(SupportSettings::class);
    $settings->suspended_drivers_may_write = false;
    $settings->save();

    $driver = Driver::factory()->create(['status' => DriverStatus::Suspended]);
    Sanctum::actingAs($driver->fresh(), ['mobile:*']);

    $this->postJson(route('api.v1.support.messages.store'), ['body' => 'Je conteste'], supportIdempotent())
        ->assertForbidden();
});

it('uploads an attachment to the private disk', function (): void {
    Storage::fake('local');
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $response = $this->postJson(
        route('api.v1.support.attachments.store'),
        ['file' => UploadedFile::fake()->image('recu.jpg', 400, 400)],
        supportIdempotent(),
    )->assertCreated();

    $attachment = MessageAttachment::query()->sole();
    expect($attachment->message_id)->toBeNull()
        ->and($attachment->uploaded_by_driver_id)->toBe($driver->id)
        ->and($response->json('data.url'))->toContain('signature')
        // Le chemin de stockage ne sort jamais.
        ->and($response->json('data'))->not->toHaveKey('path');

    Storage::disk('local')->assertExists($attachment->path);
});

it('refuses a non image attachment', function (): void {
    // Aucun antivirus dans la chaîne : v1 accepte les images seules.
    Storage::fake('local');
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(
        route('api.v1.support.attachments.store'),
        ['file' => UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf')],
        supportIdempotent(),
    )->assertStatus(422);
});

it('sends a message carrying an attachment', function (): void {
    Storage::fake('local');
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $upload = $this->postJson(
        route('api.v1.support.attachments.store'),
        ['file' => UploadedFile::fake()->image('recu.jpg', 400, 400)],
        supportIdempotent(),
    )->assertCreated();

    $this->postJson(
        route('api.v1.support.messages.store'),
        ['attachment_ids' => [$upload->json('data.id')]],
        supportIdempotent(),
    )
        ->assertCreated()
        ->assertJsonPath('data.type', 'attachment')
        ->assertJsonCount(1, 'data.attachments');

    expect(MessageAttachment::query()->sole()->message_id)->not->toBeNull();
});

it('refuses an attachment uploaded by another driver', function (): void {
    Storage::fake('local');
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();
    $attachment = MessageAttachment::factory()->create([
        'message_id' => null,
        'uploaded_by_driver_id' => $other->id,
    ]);
    Sanctum::actingAs($mine, ['mobile:*']);

    $this->postJson(
        route('api.v1.support.messages.store'),
        ['attachment_ids' => [$attachment->id]],
        supportIdempotent(),
    )->assertStatus(422);
});

it('refuses an attachment already sent', function (): void {
    // Sinon une pièce jointe publiée dans un fil pourrait être recollée
    // ailleurs.
    Storage::fake('local');
    $driver = Driver::factory()->create();
    $message = app(MessageService::class)->sendFromDriver($driver, 'Premier');
    $attachment = MessageAttachment::factory()->create([
        'message_id' => $message->id,
        'uploaded_by_driver_id' => $driver->id,
    ]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(
        route('api.v1.support.messages.store'),
        ['attachment_ids' => [$attachment->id]],
        supportIdempotent(),
    )->assertStatus(422);
});

it('notifies the driver when an agent replies', function (): void {
    $driver = Driver::factory()->create(['fcm_token' => 'jeton-de-test']);
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = User::factory()->create();
    $request = app(SupportRequestService::class)->createFromTriage(
        $conversation,
        SupportRequestCategory::Other,
        $agent,
    );

    app(MessageService::class)->sendFromStaff($request->fresh(), $agent, 'Nous regardons');

    // Écrite en base d'abord : c'est elle que l'écran « Notifications » lit.
    $notification = $driver->fresh()->notifications()->sole();
    expect($notification->data['type'])->toBe('support_message')
        ->and($notification->data['deeplink'])->toBe('wigo://support');

    /** @var LogPushSender $push */
    $push = app(PushSender::class);
    expect($push->sent())->toHaveCount(1)
        // FCM n'accepte que des chaînes dans un message data-only.
        ->and($push->sent()[0]['data']['title'])->toBeString();
});

it('does not notify the driver about their own message', function (): void {
    $driver = Driver::factory()->create(['fcm_token' => 'jeton-de-test']);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.support.messages.store'), ['body' => 'Bonjour'], supportIdempotent())
        ->assertCreated();

    expect($driver->fresh()->notifications()->count())->toBe(0);
});

it('skips the push when the driver has no token', function (): void {
    $driver = Driver::factory()->create(['fcm_token' => null]);
    app(MessageService::class)->sendFromDriver($driver, 'Une question');
    $conversation = Conversation::query()->where('driver_id', $driver->id)->sole();
    $agent = User::factory()->create();
    $request = app(SupportRequestService::class)->createFromTriage(
        $conversation,
        SupportRequestCategory::Other,
        $agent,
    );

    app(MessageService::class)->sendFromStaff($request->fresh(), $agent, 'Nous regardons');

    /** @var LogPushSender $push */
    $push = app(PushSender::class);
    expect($push->sent())->toBeEmpty()
        // La ligne en base est écrite quand même : le push n'est qu'un réveil.
        ->and($driver->fresh()->notifications()->count())->toBe(1);
});
