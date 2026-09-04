<?php

namespace App\Livewire\Announcements;

use App\Enums\AnnouncementMediaType;
use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Livewire\Concerns\InteractsWithCurrentUser;
use App\Models\Announcement;
use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
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
    use InteractsWithCurrentUser, WithFileUploads;

    /**
     * Défaut du carrousel de l'accueil, en secondes.
     */
    private const DEFAULT_DURATION = 5;

    public bool $formOpen = false;

    public ?string $editingId = null;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|file|mimes:jpg,jpeg,png,webp,mp4,webm|max:20480')]
    public mixed $media = null;

    /**
     * Durée d'affichage de la diapositive sur l'accueil mobile, en secondes.
     */
    #[Validate('required|integer|min:1|max:60')]
    public int $duration = self::DEFAULT_DURATION;

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
        $this->duration = $announcement->duration;
        $this->active = $announcement->is_active;
        $this->media = null;
        $this->formOpen = true;
    }

    public function save(): void
    {
        Gate::authorize('manageAnnouncements');

        $rules = $this->editingId === null
            ? ['media' => 'required|file|mimes:jpg,jpeg,png,webp,mp4,webm|max:20480']
            : [];

        $this->validate(array_merge([
            'title' => 'required|string|max:255',
            'duration' => 'required|integer|min:1|max:60',
        ], $rules));

        $attributes = [
            'title' => $this->title,
            'duration' => $this->duration,
            'is_active' => $this->active,
        ];

        // Le type se lit sur le type MIME du fichier : le demander en plus du
        // média laissait les deux se contredire. À la modification sans nouveau
        // fichier, le type déjà enregistré reste le bon.
        if ($this->media !== null) {
            $attributes['media_type'] = AnnouncementMediaType::fromMimeType($this->media->getMimeType());
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
        Gate::authorize('manageAnnouncements');

        $ids = Announcement::query()->orderBy('order')->pluck('id')->reject(fn (string $existingId): bool => $existingId === $id)->values();

        $ids->splice($position, 0, [$id]);

        foreach ($ids as $index => $announcementId) {
            Announcement::query()->whereKey($announcementId)->update(['order' => $index]);
        }
    }

    /**
     * Publie ou retire une bannière.
     *
     * Droit distinct de la rédaction : préparer une annonce et décider qu'elle
     * s'affiche à tous les conducteurs ne sont pas la même décision.
     */
    public function toggle(string $id): void
    {
        Gate::authorize('publishAnnouncement');

        $announcement = Announcement::query()->findOrFail($id);
        $announcement->update(['is_active' => ! $announcement->is_active]);

        AuditLog::record(
            action: ($announcement->is_active ? AuditAction::AnnouncementPublished : AuditAction::AnnouncementWithdrawn)->value,
            summary: $announcement->is_active
                ? "{$this->actor()->fullName()} a publié l'annonce « {$announcement->title} »."
                : "{$this->actor()->fullName()} a retiré l'annonce « {$announcement->title} ».",
            subject: $announcement,
            by: $this->actor(),
        );
    }

    public function duplicate(string $id): void
    {
        Gate::authorize('manageAnnouncements');

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
        Gate::authorize('manageAnnouncements');

        if ($this->confirmingDeleteId === null) {
            return;
        }

        $announcement = Announcement::query()->findOrFail($this->confirmingDeleteId);
        $title = $announcement->title;

        $announcement->delete();

        AuditLog::record(
            action: AuditAction::AnnouncementDeleted->value,
            summary: "{$this->actor()->fullName()} a supprimé l'annonce « {$title} ».",
            by: $this->actor(),
        );

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
        $this->reset(['editingId', 'title', 'media', 'active']);
        $this->duration = self::DEFAULT_DURATION;
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
