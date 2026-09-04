<?php

namespace App\Livewire\Vehicles;

use App\Enums\BackOfficeModule;
use App\Models\Vehicle;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fiche d'un véhicule : son identité, son affectation et son état de
 * synchronisation.
 *
 * Volontairement sans indicateurs ni onglets : rien dans le schéma ne pointe
 * vers un véhicule (ni course, ni entretien, ni commande), et la fiche
 * conducteur a déjà montré ce que coûtent des cartes remplies de tirets. On
 * n'affiche que ce qu'on tient vraiment.
 *
 * Aucune action : le parc est synchronisé depuis Yango, l'affectation lui
 * appartient (cf. `.ai/rules/models.md`).
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Vehicles])]
class Show extends Component
{
    public Vehicle $vehicle;

    public function mount(Vehicle $vehicle): void
    {
        $this->vehicle = $vehicle->load(['driver', 'vehicleModel.vehicleBrand']);
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'livewire.vehicles.show';

        return view($view);
    }
}
