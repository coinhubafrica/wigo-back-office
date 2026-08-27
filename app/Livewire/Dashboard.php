<?php

namespace App\Livewire;

use App\Enums\BackOfficeModule;
use App\Models\Driver;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tableau de bord : agrégats du parc. Les indicateurs métier (courses de la
 * semaine, requêtes ouvertes, recharges en attente) arriveront avec leurs
 * modules respectifs.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Dashboard])]
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.dashboard', [
            'activeDrivers' => Driver::query()->where('status', 'active')->count(),
            'suspendedDrivers' => Driver::query()->where('status', 'suspended')->count(),
        ]);
    }
}
