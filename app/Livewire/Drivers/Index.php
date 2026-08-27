<?php

namespace App\Livewire\Drivers;

use App\Enums\BackOfficeModule;
use App\Enums\DriverStatus;
use App\Models\Driver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Liste des conducteurs : recherche, filtre par statut. Les indicateurs
 * dépendant de Fleet (courses de la semaine, solde Yango) et de la CNPS
 * arriveront avec leurs modules respectifs.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Drivers])]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $status = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function filterByStatus(?string $status): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = null;
        $this->resetPage();
    }

    public function render(): View
    {
        $drivers = Driver::query()
            ->with('vehicle')
            ->when($this->search !== '', function (Builder $query): void {
                $term = "%{$this->search}%";
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhereHas('vehicle', function (Builder $query) use ($term): void {
                            $query->where('plate_number', 'like', $term);
                        });
                });
            })
            ->when($this->status !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->orderBy('last_name')
            ->paginate(20);

        return view('livewire.drivers.index', [
            'drivers' => $drivers,
            'statusCounts' => [
                null => Driver::query()->count(),
                DriverStatus::Active->value => Driver::query()->where('status', DriverStatus::Active)->count(),
                DriverStatus::Suspended->value => Driver::query()->where('status', DriverStatus::Suspended)->count(),
                DriverStatus::Dormant->value => Driver::query()->where('status', DriverStatus::Dormant)->count(),
            ],
        ]);
    }
}
