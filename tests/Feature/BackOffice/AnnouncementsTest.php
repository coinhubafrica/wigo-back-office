<?php

namespace Tests\Feature\BackOffice;

use App\Enums\AnnouncementMediaType;
use App\Enums\BackOfficeModule;
use App\Livewire\Announcements\Index;
use App\Models\Announcement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AnnouncementsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
    }

    public function test_a_permitted_user_reaches_the_announcements_page(): void
    {
        Announcement::factory()->create(['title' => 'Vente de pièces auto']);

        $this->actingAs($this->user('bonus'))
            ->get(route(BackOfficeModule::Announcements->route()))
            ->assertOk()
            ->assertSee('Vente de pièces auto');
    }

    public function test_a_user_without_the_permission_gets_403(): void
    {
        $this->actingAs($this->user('gestionnaire'))
            ->get(route(BackOfficeModule::Announcements->route()))
            ->assertForbidden();
    }

    public function test_creating_an_announcement_stores_the_uploaded_image(): void
    {
        $file = UploadedFile::fake()->image('banniere.jpg');

        Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->call('newAnnouncement')
            ->set('title', 'JCBL 2026')
            ->set('mediaType', 'image')
            ->set('media', $file)
            ->call('save')
            ->assertHasNoErrors();

        $announcement = Announcement::query()->where('title', 'JCBL 2026')->firstOrFail();

        $this->assertSame(AnnouncementMediaType::Image, $announcement->media_type);
        $this->assertTrue($announcement->is_active);
        Storage::disk('public')->assertExists($announcement->media_url);
    }

    public function test_creating_an_announcement_requires_a_media_file(): void
    {
        Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->call('newAnnouncement')
            ->set('title', 'JCBL 2026')
            ->call('save')
            ->assertHasErrors(['media' => 'required']);
    }

    public function test_a_new_announcement_gets_the_next_order(): void
    {
        Announcement::factory()->create(['order' => 3]);

        Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->call('newAnnouncement')
            ->set('title', 'Nouvelle')
            ->set('media', UploadedFile::fake()->image('b.jpg'))
            ->call('save');

        $this->assertSame(4, Announcement::query()->where('title', 'Nouvelle')->value('order'));
    }

    public function test_editing_an_announcement_without_a_new_file_keeps_the_existing_media(): void
    {
        $announcement = Announcement::factory()->create(['media_url' => 'announcements/original.jpg']);

        Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->call('edit', $announcement->id)
            ->set('title', 'Titre modifié')
            ->call('save');

        $announcement->refresh();
        $this->assertSame('Titre modifié', $announcement->title);
        $this->assertSame('announcements/original.jpg', $announcement->media_url);
    }

    public function test_toggling_flips_the_active_state(): void
    {
        $announcement = Announcement::factory()->create(['is_active' => true]);

        Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->call('toggle', $announcement->id);

        $this->assertFalse($announcement->fresh()->is_active);
    }

    public function test_duplicating_creates_an_inactive_copy(): void
    {
        $announcement = Announcement::factory()->create([
            'title' => 'JCBL 2026',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->call('duplicate', $announcement->id);

        $copy = Announcement::query()->where('title', 'JCBL 2026 (copie)')->firstOrFail();
        $this->assertFalse($copy->is_active);
        $this->assertSame(2, Announcement::query()->count());
    }

    public function test_reordering_moves_the_dragged_item_to_the_target_position(): void
    {
        $first = Announcement::factory()->create(['title' => 'First', 'order' => 0]);
        $second = Announcement::factory()->create(['title' => 'Second', 'order' => 1]);
        $third = Announcement::factory()->create(['title' => 'Third', 'order' => 2]);

        Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->call('reorder', $third->id, 0);

        $this->assertSame(0, $third->fresh()->order);
        $this->assertSame(1, $first->fresh()->order);
        $this->assertSame(2, $second->fresh()->order);
    }

    public function test_reordering_keeps_order_contiguous_and_zero_based(): void
    {
        Announcement::factory()->create(['order' => 5]);
        $moved = Announcement::factory()->create(['order' => 9]);
        Announcement::factory()->create(['order' => 20]);

        Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->call('reorder', $moved->id, 1);

        $this->assertSame([0, 1, 2], Announcement::query()->orderBy('order')->pluck('order')->all());
    }

    public function test_deleting_requires_confirmation_first(): void
    {
        $announcement = Announcement::factory()->create();

        Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->call('delete');

        $this->assertModelExists($announcement);
    }

    public function test_confirming_deletion_removes_the_announcement(): void
    {
        $announcement = Announcement::factory()->create();

        Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->call('confirmDelete', $announcement->id)
            ->call('delete');

        $this->assertModelMissing($announcement);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
