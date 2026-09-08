<?php

namespace App\Livewire\Vehicles;

use App\Enums\BackOfficeModule;
use App\Models\Vehicle;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Liste du parc : recherche par plaque, marque, modèle ou conducteur, et
 * filtre sur l'affectation.
 *
 * Le parc vient de Yango et ne se saisit pas ici (cf. `.ai/rules/models.md`) :
 * l'écran lit, il ne crée ni ne supprime. Le filtre « non affectés » est le
 * seul qui compte à l'exploitation — une voiture sans conducteur ne roule pas.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Vehicles])]
class Index extends Component
{
    use WithPagination;

    public const FILTER_ASSIGNED = 'assigned';

    public const FILTER_UNASSIGNED = 'unassigned';

    public const FILTER_INACTIVE = 'inactive';

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $filter = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function filterBy(?string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->filter = null;
        $this->resetPage();
    }

    public function render(): View
    {
        $vehicles = $this->baseQuery()
            ->when($this->search !== '', function (Builder $query): void {
                $term = "%{$this->search}%";
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('plate_number', 'like', $term)
                        ->orWhere('brand', 'like', $term)
                        ->orWhere('model', 'like', $term)
                        ->orWhere('yango_id', 'like', $term)
                        ->orWhereHas('driver', function (Builder $query) use ($term): void {
                            $query->where('first_name', 'like', $term)
                                ->orWhere('last_name', 'like', $term)
                                ->orWhere('phone', 'like', $term);
                        });
                });
            })
            ->orderBy('plate_number')
            ->paginate(20);

        /** @var view-string $view */
        $view = 'livewire.vehicles.index';

        // Une requête pour les quatre puces : mêmes prédicats que `baseQuery()`.
        $counts = Vehicle::query()
            ->selectRaw(implode(', ', [
                'count(*) as total',
                'sum(case when driver_id is not null and is_active = 1 then 1 else 0 end) as assigned',
                'sum(case when driver_id is null and is_active = 1 then 1 else 0 end) as unassigned',
                'sum(case when is_active = 0 then 1 else 0 end) as inactive',
            ]))
            ->toBase()
            ->first();

        return view($view, [
            'vehicles' => $vehicles,
            'counts' => [
                null => (int) ($counts->total ?? 0),
                self::FILTER_ASSIGNED => (int) ($counts->assigned ?? 0),
                self::FILTER_UNASSIGNED => (int) ($counts->unassigned ?? 0),
                self::FILTER_INACTIVE => (int) ($counts->inactive ?? 0),
            ],
        ]);
    }

    /**
     * @return Builder<Vehicle>
     */
    private function baseQuery(): Builder
    {
        return Vehicle::query()
            ->with('driver')
            ->when($this->filter === self::FILTER_ASSIGNED, fn (Builder $query) => $query
                ->whereNotNull('driver_id')->where('is_active', true))
            ->when($this->filter === self::FILTER_UNASSIGNED, fn (Builder $query) => $query
                ->whereNull('driver_id')->where('is_active', true))
            ->when($this->filter === self::FILTER_INACTIVE, fn (Builder $query) => $query
                ->where('is_active', false));
    }
}
