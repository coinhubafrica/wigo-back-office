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

it('404s an orphan attachment', function (): void {
    // Téléversée, jamais rattachée : elle n'appartient à aucun fil.
    $orphan = MessageAttachment::factory()->orphan()->create(['disk' => 'private']);
    Storage::disk('private')->put($orphan->path, 'binaire');

    $this->actingAs(attachmentsUser('gestionnaire'))
        ->get(route('bo.support-requests.attachment', $orphan))
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
