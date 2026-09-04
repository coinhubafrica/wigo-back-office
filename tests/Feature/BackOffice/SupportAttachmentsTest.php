<?php

/**
 * Les pièces jointes du fil : montrées et ouvrables depuis le back-office, par
 * la route protégée seulement — le disque est privé.
 */

use App\Livewire\SupportRequests\Index;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('private');
});

function attachmentsUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function attachedFile(string $name = 'carte-grise.jpg', string $mime = 'image/jpeg'): MessageAttachment
{
    $driver = Driver::factory()->create();
    $conversation = Conversation::factory()->create(['driver_id' => $driver->id]);
    $message = Message::factory()->forConversation($conversation)->attachment()->create();

    $attachment = MessageAttachment::factory()->fromDriver($driver)->create([
        'message_id' => $message->id,
        'disk' => 'private',
        'path' => 'messages/'.$message->id.'/'.$name,
        'original_name' => $name,
        'mime_type' => $mime,
        'size_bytes' => 1_258_291,
    ]);

    Storage::disk('private')->put($attachment->path, 'binaire');

    return $attachment;
}

it('streams an attachment to an authorised agent', function (): void {
    $attachment = attachedFile();

    $this->actingAs(attachmentsUser('gestionnaire'))
        ->get(route('bo.support-requests.attachment', $attachment))
        ->assertOk()
        ->assertHeader('content-disposition', 'inline; filename="carte-grise.jpg"');
});

it('closes the attachment route without the support permission', function (): void {
    // `admin` n'a pas `module.support-requests`.
    $attachment = attachedFile();

    $this->actingAs(attachmentsUser('admin'))
        ->get(route('bo.support-requests.attachment', $attachment))
        ->assertForbidden();
});

it('refuses an anonymous request', function (): void {
    $attachment = attachedFile();

    $this->get(route('bo.support-requests.attachment', $attachment))
        ->assertRedirect();
});

it('refuses an orphan attachment without saying it exists', function (): void {
    // Téléversée, jamais rattachée : elle n'appartient à aucun fil. La réponse
    // est la même que pour un identifiant inconnu — 404 aurait dit « celle-ci
    // existe, mais pas pour vous ».
    $orphan = MessageAttachment::factory()->orphan()->create(['disk' => 'private']);
    Storage::disk('private')->put($orphan->path, 'binaire');

    $this->actingAs(attachmentsUser('gestionnaire'))
        ->get(route('bo.support-requests.attachment', $orphan))
        ->assertForbidden();
});

it('answers an unknown attachment id exactly like a forbidden one', function (): void {
    /*
     * Le cœur de la correction : le code de statut ne doit pas permettre
     * d'énumérer les identifiants. Une pièce inconnue et une pièce orpheline
     * répondent tous deux 403, sans corps distinguable.
     */
    $agent = attachmentsUser('gestionnaire');

    $unknown = $this->actingAs($agent)
        ->get(route('bo.support-requests.attachment', '01jzzzzzzzzzzzzzzzzzzzzzzz'));

    $orphan = MessageAttachment::factory()->orphan()->create(['disk' => 'private']);

    $forbidden = $this->actingAs($agent)
        ->get(route('bo.support-requests.attachment', $orphan));

    $unknown->assertForbidden();
    $forbidden->assertForbidden();

    expect($unknown->getContent())->toBe($forbidden->getContent());
});

it('404s only once the request is authorised', function (): void {
    // Un fichier absent du disque est une anomalie de stockage : la dire ne
    // révèle rien, puisque l'accès à la pièce est déjà accordé.
    $attachment = attachedFile();
    Storage::disk('private')->delete($attachment->path);

    $this->actingAs(attachmentsUser('gestionnaire'))
        ->get(route('bo.support-requests.attachment', $attachment))
        ->assertNotFound();
});

it('404s when the file is gone from the disk', function (): void {
    $attachment = attachedFile();
    Storage::disk('private')->delete($attachment->path);

    $this->actingAs(attachmentsUser('gestionnaire'))
        ->get(route('bo.support-requests.attachment', $attachment))
        ->assertNotFound();
});

it('shows an image inline and links it to the protected route', function (): void {
    $attachment = attachedFile();

    Livewire::actingAs(attachmentsUser('gestionnaire'))
        ->test(Index::class)
        ->call('select', $attachment->message->conversation_id)
        ->assertSee(route('bo.support-requests.attachment', $attachment), escape: false)
        ->assertSee('<img', escape: false)
        // Jamais le chemin du disque dans la page.
        ->assertDontSee($attachment->path);
});

it('offers a non-image as a download chip with its size', function (): void {
    $attachment = attachedFile('facture.pdf', 'application/pdf');

    Livewire::actingAs(attachmentsUser('gestionnaire'))
        ->test(Index::class)
        ->call('select', $attachment->message->conversation_id)
        ->assertSee('facture.pdf')
        ->assertSee('1,2 Mo')
        ->assertDontSee('<img', escape: false);
});
