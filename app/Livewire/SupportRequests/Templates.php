<?php

namespace App\Livewire\SupportRequests;

use App\Enums\BackOfficeModule;
use App\Enums\SupportRequestCategory;
use App\Models\MessageTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Réponses types du support : les phrases que les agents écrivent dix fois par
 * jour, saisies une seule.
 *
 * `shortcut` est la commande tapée dans le champ de saisie (« /remb ») ; elle
 * est unique pour qu'une frappe ne désigne jamais deux réponses.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::SupportRequests])]
class Templates extends Component
{
    public bool $formOpen = false;

    public ?string $editingId = null;

    public string $title = '';

    public string $body = '';

    public ?string $category = null;

    public string $shortcut = '';

    public bool $active = true;

    public ?string $confirmingDeleteId = null;

    public function newTemplate(): void
    {
        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit(string $id): void
    {
        $template = MessageTemplate::query()->findOrFail($id);

        $this->editingId = $template->id;
        $this->title = $template->title;
        $this->body = $template->body;
        $this->category = $template->category;
        $this->shortcut = (string) $template->shortcut;
        $this->active = $template->is_active;
        $this->formOpen = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4000'],
            'category' => ['nullable', 'string', 'in:'.implode(',', array_column(SupportRequestCategory::cases(), 'value'))],
            'shortcut' => [
                'nullable', 'string', 'max:60', 'regex:/^\/[a-z0-9-]+$/',
                'unique:message_templates,shortcut'.($this->editingId === null ? '' : ','.$this->editingId),
            ],
        ]);

        $attributes = [
            ...$validated,
            'shortcut' => $this->shortcut === '' ? null : $this->shortcut,
            'is_active' => $this->active,
        ];

        if ($this->editingId === null) {
            // L'auteur n'est renseigné qu'à la création : une modification ne
            // doit pas réattribuer la réponse type à celui qui la retouche.
            MessageTemplate::query()->create([...$attributes, 'created_by_user_id' => Auth::id()]);
        } else {
            MessageTemplate::query()->whereKey($this->editingId)->update($attributes);
        }

        $this->formOpen = false;
        $this->resetForm();
        $this->dispatch('toast', message: __('backoffice.support_requests.template_saved'));
    }

    /** Ferme le formulaire sans enregistrer (bouton, Échap, fond). */
    public function closeForm(): void
    {
        $this->formOpen = false;
    }

    public function toggle(string $id): void
    {
        $template = MessageTemplate::query()->findOrFail($id);
        $template->forceFill(['is_active' => ! $template->is_active])->save();
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
        if ($this->confirmingDeleteId !== null) {
            MessageTemplate::query()->whereKey($this->confirmingDeleteId)->delete();
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('toast', message: __('backoffice.support_requests.template_deleted'));
    }

    public function render(): View
    {
        return view('livewire.support-requests.templates', [
            'templates' => MessageTemplate::query()->orderBy('title')->get(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'title', 'body', 'category', 'shortcut', 'active']);
        $this->resetValidation();
    }
}
