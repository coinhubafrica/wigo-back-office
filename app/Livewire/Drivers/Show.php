<?php

namespace App\Livewire\Drivers;

use App\Enums\BackOfficeModule;
use App\Enums\DriverStatus;
use App\Http\Resources\CnpsStatementPayload;
use App\Models\Driver;
use App\Services\Cnps\CnpsStatementService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fiche 360° d'un conducteur. Les onglets Requêtes et Transactions du
 * prototype arriveront avec leurs modules respectifs ; l'identité, le véhicule
 * affecté et le suivi CNPS sont réels ici.
 *
 * La photo de profil n'est pas modérée : le conducteur la change depuis
 * l'application, la fiche ne fait que l'afficher.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Drivers])]
class Show extends Component
{
    public Driver $driver;

    public bool $showSuspendForm = false;

    public string $suspensionReason = '';

    /**
     * Modale de confirmation plutôt que `wire:confirm` : le dialogue natif
     * bloque l'automatisation navigateur, comme constaté sur les recharges.
     */
    public bool $confirmingReactivation = false;

    public function mount(Driver $driver): void
    {
        $this->driver = $driver->load('vehicle');
    }

    public function suspend(): void
    {
        $this->validate([
            'suspensionReason' => ['required', 'string', 'max:255'],
        ]);

        $this->driver->update([
            'status' => DriverStatus::Suspended,
            'suspension_reason' => $this->suspensionReason,
        ]);

        $this->showSuspendForm = false;
        $this->suspensionReason = '';

        $this->dispatch('toast', message: __('backoffice.drivers.driver_suspended'));
    }

    public function confirmReactivate(): void
    {
        $this->confirmingReactivation = true;
    }

    public function cancelReactivate(): void
    {
        $this->confirmingReactivation = false;
    }

    public function reactivate(): void
    {
        $this->confirmingReactivation = false;

        $this->driver->update([
            'status' => DriverStatus::Active,
            'suspension_reason' => null,
        ]);

        $this->dispatch('toast', message: __('backoffice.drivers.reactivated'));
    }

    /**
     * Relevé CNPS du conducteur : le mois en cours pour la carte du haut, les
     * douze précédents pour le panneau.
     *
     * Même service que l'API mobile — le conducteur et l'agent lisent le même
     * relevé, il n'y a pas deux façons de compter.
     *
     * @return array<string, mixed>
     */
    public function cnpsStatement(CnpsStatementService $statement): array
    {
        return CnpsStatementPayload::build($this->driver, $statement);
    }

    public function render(CnpsStatementService $statement): View
    {
        return view('livewire.drivers.show', [
            'driver' => $this->driver->fresh('vehicle'),
            'cnps' => $this->cnpsStatement($statement),
        ]);
    }
}
