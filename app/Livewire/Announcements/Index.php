<?php

namespace App\Livewire\Announcements;

use App\Enums\AnnouncementMediaType;
use App\Enums\BackOfficeModule;
use App\Models\Announcement;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * CRUD des bannières de l'accueil mobile : upload média, activer/désactiver,
 * dupliquer, supprimer.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Announcements])]
class Index extends Component
{
    use WithFileUploads;

    public bool $formOpen = false;

    public ?string $editingId = null;

    #[Validate('required|string|max:255')]
    public string $title = '';

    /**
     * @var 'image'|'video'
     */
    #[Validate('required|in:image,video')]
    public string $mediaType = 'image';

    #[Validate('nullable|file|mimes:jpg,jpeg,png,webp,mp4,webm|max:20480')]
    public mixed $media = null;

    public bool $active = true;

    public ?string $confirmingDeleteId = null;

    public function newAnnouncement(): void
    {
        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit(string $id): void
    {
        $announcement = Announcement::query()->findOrFail($id);

        $this->editingId = $announcement->id;
        $this->title = $announcement->title;
        $this->mediaType = $announcement->media_type->value;
        $this->active = $announcement->is_active;
        $this->media = null;
        $this->formOpen = true;
    }

    public function save(): void
    {
        $rules = $this->editingId === null
            ? ['media' => 'required|file|mimes:jpg,jpeg,png,webp,mp4,webm|max:20480']
            : [];

        $this->validate(array_merge([
            'title' => 'required|string|max:255',
            'mediaType' => 'required|in:image,video',
        ], $rules));

        $attributes = [
            'title' => $this->title,
            'media_type' => AnnouncementMediaType::from($this->mediaType),
            'is_active' => $this->active,
        ];

        if ($this->media !== null) {
            $attributes['media_url'] = $this->media->store(path: 'announcements');
        }

        if ($this->editingId === null) {
            $attributes['order'] = (int) Announcement::query()->max('order') + 1;
            Announcement::query()->create($attributes);
            $this->dispatch('toast', message: __('backoffice.announcements.created'));
        } else {
            Announcement::query()->findOrFail($this->editingId)->update($attributes);
            $this->dispatch('toast', message: __('backoffice.announcements.updated'));
        }

        $this->formOpen = false;
        $this->resetForm();
    }

    /**
     * Réordonner après un glisser-déposer. `$position` est l'index (0-based)
     * cible fourni par wire:sort ; on réaffecte `order` à toute la liste pour
     * qu'il reste contigu.
     */
    public function reorder(string $id, int $position): void
    {
        $ids = Announcement::query()->orderBy('order')->pluck('id')->reject(fn (string $existingId): bool => $existingId === $id)->values();

        $ids->splice($position, 0, [$id]);

        foreach ($ids as $index => $announcementId) {
            Announcement::query()->whereKey($announcementId)->update(['order' => $index]);
        }
    }

    public function toggle(string $id): void
    {
        $announcement = Announcement::query()->findOrFail($id);
        $announcement->update(['is_active' => ! $announcement->is_active]);
    }

    public function duplicate(string $id): void
    {
        $announcement = Announcement::query()->findOrFail($id);

        $announcement->replicate()->fill([
            'title' => $announcement->title.' ('.__('backoffice.announcements.copy_suffix').')',
            'is_active' => false,
        ])->save();

        $this->dispatch('toast', message: __('backoffice.announcements.duplicated'));
    }

    public function confirmDelete(string $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function delete(): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        Announcement::query()->findOrFail($this->confirmingDeleteId)->delete();

        $this->confirmingDeleteId = null;
        $this->dispatch('toast', message: __('backoffice.announcements.deleted'));
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'title', 'mediaType', 'media', 'active']);
        $this->mediaType = 'image';
        $this->active = true;
        $this->resetValidation();
    }

    public function render(): View
    {
        /** @var Collection<int, Announcement> $announcements */
        $announcements = Announcement::query()->orderBy('order')->get();

        return view('livewire.announcements.index', [
            'announcements' => $announcements,
            'total' => $announcements->count(),
            'activeCount' => $announcements->where('is_active', true)->count(),
            'pausedCount' => $announcements->where('is_active', false)->count(),
            'videoCount' => $announcements->where('media_type', AnnouncementMediaType::Video)->count(),
        ]);
    }
}
