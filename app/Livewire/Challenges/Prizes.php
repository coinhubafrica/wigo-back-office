<?php

namespace App\Livewire\Challenges;

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Livewire\Concerns\InteractsWithCurrentUser;
use App\Models\AuditLog;
use App\Models\Prize;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Catalogue des lots physiques attribués par les tombolas. Le stock n'est pas
 * suivi : un lot est décrit une fois puis rattaché à un challenge de type
 * tirage au sort. Rattaché au module Challenges (même permission).
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Challenges])]
class Prizes extends Component
{
    use InteractsWithCurrentUser, WithFileUploads;

    public bool $formOpen = false;

    public ?string $editingId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|image|max:4096')]
    public mixed $photo = null;

    public ?string $confirmingDeleteId = null;

    public function newPrize(): void
    {
        Gate::authorize('manageChallengePrizes');

        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit(string $id): void
    {
        Gate::authorize('manageChallengePrizes');

        $prize = Prize::query()->findOrFail($id);

        $this->editingId = $prize->id;
        $this->name = $prize->name;
        $this->photo = null;
        $this->formOpen = true;
    }

    public function save(): void
    {
        Gate::authorize('manageChallengePrizes');

        $this->validate();

        $attributes = ['name' => $this->name];

        if ($this->photo !== null) {
            $attributes['photo_url'] = $this->photo->store(path: 'prizes');
        }

        if ($this->editingId === null) {
            Prize::query()->create($attributes);
            $this->dispatch('toast', message: __('backoffice.prizes.created'));
        } else {
            Prize::query()->findOrFail($this->editingId)->update($attributes);
            $this->dispatch('toast', message: __('backoffice.prizes.updated'));
        }

        $this->formOpen = false;
        $this->resetForm();
    }

    public function confirmDelete(string $id): void
    {
        Gate::authorize('manageChallengePrizes');

        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function delete(): void
    {
        Gate::authorize('manageChallengePrizes');

        if ($this->confirmingDeleteId === null) {
            return;
        }

        $prize = Prize::query()->findOrFail($this->confirmingDeleteId);

        // Un lot déjà rattaché à une tombola reste référencé par le challenge
        // et son gagnant : on refuse la suppression plutôt que de casser la
        // trace de ce qui a été remis.
        if ($prize->challenges()->exists()) {
            $this->confirmingDeleteId = null;
            $this->dispatch('toast', message: __('backoffice.prizes.delete_blocked'));

            return;
        }

        /*
        | Enregistré avant la suppression : après, il ne reste rien à citer.
        | Seule la suppression est journalisée — créer ou renommer un lot laisse
        | la ligne comme preuve, et le lot ne porte pas de valeur (elle vit sur
        | le challenge, dans `reward_amount`), donc il n'y a pas de mouvement de
        | montant à suivre ici.
        */
        AuditLog::record(
            action: AuditAction::ChallengePrizeDeleted->value,
            summary: "{$this->actor()->fullName()} a supprimé le lot « {$prize->name} ».",
            by: $this->actor(),
            context: ['name' => $prize->name],
        );

        $prize->delete();

        $this->confirmingDeleteId = null;
        $this->dispatch('toast', message: __('backoffice.prizes.deleted'));
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'photo']);
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.challenges.prizes', [
            'prizes' => Prize::query()->withCount('challenges')->orderBy('name')->get(),
        ]);
    }
}
