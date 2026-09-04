<?php

use App\Enums\AnnouncementMediaType;
use App\Enums\BackOfficeModule;
use App\Livewire\Announcements\Index;
use App\Models\Announcement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('public');
});

it('a permitted user reaches the announcements page', function (): void {
    Announcement::factory()->create(['title' => 'Vente de pièces auto']);

    $this->actingAs(announcementsUser('bonus'))
        ->get(route(BackOfficeModule::Announcements->route()))
        ->assertOk()
        ->assertSee('Vente de pièces auto')
        // Le bouton de création est dans l'en-tête du layout et parle à la racine par évènement.
        ->assertSee("\$dispatch('open-announcement-form')", false)
        ->assertSee('x-on:open-announcement-form.window', false);
});

it('a user without the permission gets 403', function (): void {
    $this->actingAs(announcementsUser('gestionnaire'))
        ->get(route(BackOfficeModule::Announcements->route()))
        ->assertForbidden();
});

it('creating an announcement stores the uploaded image', function (): void {
    $file = UploadedFile::fake()->image('banniere.jpg');

    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('newAnnouncement')
        ->set('title', 'JCBL 2026')
        ->set('media', $file)
        ->set('duration', 8)
        ->call('save')
        ->assertHasNoErrors();

    $announcement = Announcement::query()->where('title', 'JCBL 2026')->firstOrFail();

    $this->assertSame(AnnouncementMediaType::Image, $announcement->media_type);
    $this->assertSame(8, $announcement->duration);
    $this->assertTrue($announcement->is_active);
    Storage::disk('public')->assertExists($announcement->media_url);
});

it('the media type is read from the uploaded file, not asked for', function (): void {
    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('newAnnouncement')
        ->set('title', 'Spot vidéo')
        ->set('media', UploadedFile::fake()->create('spot.mp4', 100, 'video/mp4'))
        ->call('save')
        ->assertHasNoErrors();

    $this->assertSame(
        AnnouncementMediaType::Video,
        Announcement::query()->where('title', 'Spot vidéo')->value('media_type'),
    );
});

it('the media type follows the mime type, not the file name', function (): void {
    // Une vidéo nommée « .jpg » reste une vidéo : le MIME est reniflé sur le
    // contenu, l'extension n'est qu'un bout du nom.
    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('newAnnouncement')
        ->set('title', 'Vidéo mal nommée')
        ->set('media', UploadedFile::fake()->create('trompeur.jpg', 100, 'video/mp4'))
        ->call('save')
        ->assertHasNoErrors();

    $this->assertSame(
        AnnouncementMediaType::Video,
        Announcement::query()->where('title', 'Vidéo mal nommée')->value('media_type'),
    );
});

it('a new announcement defaults to a five second slide', function (): void {
    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('newAnnouncement')
        ->set('title', 'Défaut')
        ->set('media', UploadedFile::fake()->image('b.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    $this->assertSame(5, Announcement::query()->where('title', 'Défaut')->value('duration'));
});

it('the duration must stay within one and sixty seconds', function (): void {
    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('newAnnouncement')
        ->set('title', 'Trop longue')
        ->set('media', UploadedFile::fake()->image('b.jpg'))
        ->set('duration', 61)
        ->call('save')
        ->assertHasErrors(['duration' => 'max']);
});

it('editing without a new file keeps the recorded media type', function (): void {
    $announcement = Announcement::factory()->video()->create(['media_url' => 'announcements/spot.mp4']);

    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('edit', $announcement->id)
        ->set('title', 'Titre modifié')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertSame(AnnouncementMediaType::Video, $announcement->fresh()->media_type);
});

it('creating an announcement requires a media file', function (): void {
    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('newAnnouncement')
        ->set('title', 'JCBL 2026')
        ->call('save')
        ->assertHasErrors(['media' => 'required']);
});

it('a new announcement gets the next order', function (): void {
    Announcement::factory()->create(['order' => 3]);

    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('newAnnouncement')
        ->set('title', 'Nouvelle')
        ->set('media', UploadedFile::fake()->image('b.jpg'))
        ->call('save');

    $this->assertSame(4, Announcement::query()->where('title', 'Nouvelle')->value('order'));
});

it('editing an announcement without a new file keeps the existing media', function (): void {
    $announcement = Announcement::factory()->create(['media_url' => 'announcements/original.jpg']);

    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('edit', $announcement->id)
        ->set('title', 'Titre modifié')
        ->call('save');

    $announcement->refresh();
    $this->assertSame('Titre modifié', $announcement->title);
    $this->assertSame('announcements/original.jpg', $announcement->media_url);
});

it('toggling flips the active state', function (): void {
    $announcement = Announcement::factory()->create(['is_active' => true]);

    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('toggle', $announcement->id);

    $this->assertFalse($announcement->fresh()->is_active);
});

it('duplicating creates an inactive copy', function (): void {
    $announcement = Announcement::factory()->create([
        'title' => 'JCBL 2026',
        'is_active' => true,
    ]);

    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('duplicate', $announcement->id);

    $copy = Announcement::query()->where('title', 'JCBL 2026 (copie)')->firstOrFail();
    $this->assertFalse($copy->is_active);
    $this->assertSame(2, Announcement::query()->count());
});

it('reordering moves the dragged item to the target position', function (): void {
    $first = Announcement::factory()->create(['title' => 'First', 'order' => 0]);
    $second = Announcement::factory()->create(['title' => 'Second', 'order' => 1]);
    $third = Announcement::factory()->create(['title' => 'Third', 'order' => 2]);

    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('reorder', $third->id, 0);

    $this->assertSame(0, $third->fresh()->order);
    $this->assertSame(1, $first->fresh()->order);
    $this->assertSame(2, $second->fresh()->order);
});

it('reordering keeps order contiguous and zero based', function (): void {
    Announcement::factory()->create(['order' => 5]);
    $moved = Announcement::factory()->create(['order' => 9]);
    Announcement::factory()->create(['order' => 20]);

    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('reorder', $moved->id, 1);

    $this->assertSame([0, 1, 2], Announcement::query()->orderBy('order')->pluck('order')->all());
});

it('deleting requires confirmation first', function (): void {
    $announcement = Announcement::factory()->create();

    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('delete');

    $this->assertModelExists($announcement);
});

it('confirming deletion removes the announcement', function (): void {
    $announcement = Announcement::factory()->create();

    Livewire::actingAs(announcementsUser('bonus'))
        ->test(Index::class)
        ->call('confirmDelete', $announcement->id)
        ->call('delete');

    $this->assertModelMissing($announcement);
});

function announcementsUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
