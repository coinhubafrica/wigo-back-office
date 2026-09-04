<?php

/**
 * L'image d'un envoi groupé : téléversée une fois, déposée dans chaque fil,
 * et servie par la route protégée seulement — le disque est privé.
 */

use App\Enums\MessageType;
use App\Enums\SystemMessageEvent;
use App\Livewire\Campaigns\Index;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Support\CampaignDispatcher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * Un agent du back-office portant `$role`. Nommée par le sujet du fichier :
 * les helpers Pest sont globaux à toute la suite.
 */
function campaignImageUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}

/**
 * Campagne portant une image réellement présente sur le disque privé.
 */
/** @param array<string, mixed> $attributes */
function campaignWithImage(array $attributes = []): Campaign
{
    $campaign = Campaign::factory()->create([
        'title' => 'Maintenance dimanche',
        'image_disk' => 'local',
        'image_path' => 'campaigns/visuel.jpg',
        'image_name' => 'visuel.jpg',
        'image_mime' => 'image/jpeg',
        'image_size_bytes' => 24_576,
        ...$attributes,
    ]);

    Storage::disk('local')->put('campaigns/visuel.jpg', 'binaire');

    return $campaign;
}

it('stores the image on the private disk when composing', function (): void {
    Livewire::actingAs(campaignImageUser('bonus'))
        ->test(Index::class)
        ->set('title', 'Maintenance dimanche')
        ->set('body', 'Le service sera interrompu de 2 h à 4 h.')
        ->set('image', UploadedFile::fake()->image('visuel.jpg'))
        ->call('saveDraft')
        ->assertHasNoErrors();

    $campaign = Campaign::query()->firstOrFail();

    expect($campaign->hasImage())->toBeTrue()
        ->and($campaign->image_disk)->toBe('local')
        ->and($campaign->image_name)->toBe('visuel.jpg');

    Storage::disk('local')->assertExists($campaign->image_path);
});

it('refuses a file that is not an image', function (): void {
    Livewire::actingAs(campaignImageUser('bonus'))
        ->test(Index::class)
        ->set('title', 'Maintenance dimanche')
        ->set('body', 'Le service sera interrompu.')
        ->set('image', UploadedFile::fake()->create('barème.pdf', 40, 'application/pdf'))
        ->call('saveDraft')
        ->assertHasErrors('image');

    expect(Campaign::query()->count())->toBe(0);
});

it('refuses an image heavier than five megabytes', function (): void {
    Livewire::actingAs(campaignImageUser('bonus'))
        ->test(Index::class)
        ->set('title', 'Maintenance dimanche')
        ->set('body', 'Le service sera interrompu.')
        ->set('image', UploadedFile::fake()->image('lourde.jpg')->size(6_000))
        ->call('saveDraft')
        ->assertHasErrors('image');

    expect(Campaign::query()->count())->toBe(0);
});

it('sends a campaign without an image, as before', function (): void {
    Driver::factory()->count(2)->create();
    $campaign = Campaign::factory()->create();

    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->messages()->count())->toBe(2)
        ->and(Message::query()->has('attachments')->count())->toBe(0);
});

it('drops the image in every recipient thread', function (): void {
    Driver::factory()->count(3)->create();
    $campaign = campaignWithImage();

    app(CampaignDispatcher::class)->dispatch($campaign);

    $messages = $campaign->messages()->with('attachments')->get();

    expect($messages)->toHaveCount(3);

    foreach ($messages as $message) {
        expect($message->attachments)->toHaveCount(1)
            ->and($message->attachments->first()->mime_type)->toBe('image/jpeg');
    }
});

it('stores the file once, however many recipients', function (): void {
    // Cinq mille conducteurs ne doivent pas faire cinq mille copies du même
    // JPEG : les lignes sont des métadonnées, le fichier est unique.
    Driver::factory()->count(4)->create();
    $campaign = campaignWithImage();

    app(CampaignDispatcher::class)->dispatch($campaign);

    $paths = $campaign->messages()->with('attachments')->get()
        ->flatMap(fn (Message $message) => $message->attachments->pluck('path'))
        ->unique();

    expect($paths)->toHaveCount(1)
        ->and($paths->first())->toBe($campaign->image_path)
        ->and(Storage::disk('local')->files('campaigns'))->toHaveCount(1);
});

it('keeps the campaign message a system message despite the attachment', function (): void {
    // Le type dit comment lire le message ; l'application branche sur
    // `system_event`. Le basculer en « pièce jointe » lui ferait perdre
    // l'évènement.
    Driver::factory()->create();
    $campaign = campaignWithImage();

    app(CampaignDispatcher::class)->dispatch($campaign);

    $message = $campaign->messages()->firstOrFail();

    expect($message->type)->toBe(MessageType::System)
        ->and($message->system_event)->toBe(SystemMessageEvent::CampaignMessage);
});

it('does not attach the image twice when a half-done send is resumed', function (): void {
    Driver::factory()->count(3)->create();
    $campaign = campaignWithImage();

    app(CampaignDispatcher::class)->dispatch($campaign);
    app(CampaignDispatcher::class)->dispatch($campaign);

    expect($campaign->messages()->count())->toBe(3)
        ->and(MessageAttachment::query()->count())->toBe(3);
});

it('leaves no orphan attachment behind', function (): void {
    // Une ligne jamais rattachée serait ramassée par la purge, qui supprime le
    // fichier — et l'image de tout l'envoi avec.
    Driver::factory()->count(2)->create();
    $campaign = campaignWithImage();

    app(CampaignDispatcher::class)->dispatch($campaign);

    expect(MessageAttachment::query()->whereNull('message_id')->count())->toBe(0);
});

it('serves the image to an authorised agent', function (): void {
    $campaign = campaignWithImage();

    $this->actingAs(campaignImageUser('bonus'))
        ->get(route('bo.campaigns.image', $campaign))
        ->assertOk()
        ->assertHeader('content-disposition', 'inline; filename="visuel.jpg"');
});

it('closes the image route without the campaigns permission', function (): void {
    $campaign = campaignWithImage();

    $this->actingAs(campaignImageUser('stock'))
        ->get(route('bo.campaigns.image', $campaign))
        ->assertForbidden();
});

it('answers 403 for an unknown campaign, never 404', function (): void {
    // L'écart entre 403 et 404 dirait quels identifiants existent.
    $this->actingAs(campaignImageUser('bonus'))
        ->get(route('bo.campaigns.image', '01JQZZZZZZZZZZZZZZZZZZZZZZ'))
        ->assertForbidden();
});

it('answers 403 for a campaign carrying no image', function (): void {
    $campaign = Campaign::factory()->create();

    $this->actingAs(campaignImageUser('bonus'))
        ->get(route('bo.campaigns.image', $campaign))
        ->assertForbidden();
});

it('lets each driver fetch only their own copy of the campaign image', function (): void {
    // Chaque conducteur reçoit sa propre ligne, sur son propre message : le
    // scope par conversation de l'API tient donc sans changement, et l'un ne
    // peut pas tirer la pièce jointe de l'autre.
    $drivers = Driver::factory()->count(2)->create();
    $campaign = campaignWithImage();

    app(CampaignDispatcher::class)->dispatch($campaign);

    $rows = $campaign->messages()->with('attachments')->get()
        ->flatMap(fn (Message $message) => $message->attachments);

    expect($rows)->toHaveCount(2);

    $conversationIds = $campaign->messages()->pluck('conversation_id');

    expect($conversationIds->unique())->toHaveCount(2)
        ->and($drivers->pluck('id')->sort()->values())
        ->toEqual(Conversation::query()->whereIn('id', $conversationIds)
            ->pluck('driver_id')->sort()->values());
});

it('shows the image on the campaign detail screen', function (): void {
    $campaign = campaignWithImage();

    $this->actingAs(campaignImageUser('bonus'))
        ->get(route('bo.campaigns.show', $campaign))
        ->assertOk()
        ->assertSee(route('bo.campaigns.image', $campaign));
});
