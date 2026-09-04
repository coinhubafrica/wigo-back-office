<?php

namespace App\Livewire\Drivers;

use App\Enums\BackOfficeModule;
use App\Enums\CnpsMonthStatus;
use App\Enums\DriverStatus;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
use App\Models\Driver;
use App\Services\Cnps\CnpsStatementService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Liste des conducteurs : recherche, filtre par statut, et les indicateurs
 * que l'on sait déjà tenir — solde Yango et cotisation CNPS du mois. Les
 * courses de la semaine dépendent encore de la synchronisation Fleet.
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

    public function render(CnpsStatementService $statement): View
    {
        $drivers = Driver::query()
            ->with('vehicle')
            ->when($this->search !== '', function (Builder $query): void {
                $term = "%{$this->search}%";
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('yango_id', 'like', $term)
                        ->orWhere('license_number', 'like', $term)
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
            'cnpsStatuses' => $this->cnpsStatuses($drivers, $statement),
            'statusCounts' => [
                null => Driver::query()->count(),
                DriverStatus::Active->value => Driver::query()->where('status', DriverStatus::Active)->count(),
                DriverStatus::Suspended->value => Driver::query()->where('status', DriverStatus::Suspended)->count(),
                DriverStatus::Dormant->value => Driver::query()->where('status', DriverStatus::Dormant)->count(),
            ],
        ]);
    }

    /**
     * État CNPS du mois en cours pour les conducteurs de la page.
     *
     * Deux requêtes agrégées pour toute la page, jamais une par ligne : le
     * cumul déclaré d'un côté, le montant de référence en vigueur de l'autre.
     * Le verdict lui-même reste dans `CnpsStatementService` — l'agent et le
     * conducteur ne doivent pas lire deux comptes différents.
     *
     * @param  LengthAwarePaginator<int, Driver>  $drivers
     * @return array<string, CnpsMonthStatus>
     */
    private function cnpsStatuses(LengthAwarePaginator $drivers, CnpsStatementService $statement): array
    {
        /** @var list<string> $driverIds */
        $driverIds = $drivers->pluck('id')->all();

        if ($driverIds === []) {
            return [];
        }

        $period = $statement->currentPeriod();

        $declared = CnpsDeclaration::query()
            ->whereIn('driver_id', $driverIds)
            ->where('period', $period)
            ->groupBy('driver_id')
            ->selectRaw('driver_id, sum(declared_amount) as aggregate')
            ->pluck('aggregate', 'driver_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        // Le montant en vigueur ce mois-là, conducteur par conducteur : la
        // table est append-only, on garde la ligne la plus récente parmi
        // celles déjà entrées en vigueur.
        $references = CnpsReference::query()
            ->whereIn('driver_id', $driverIds)
            ->where('effective_from', '<=', now()->endOfMonth())
            ->latestFirst()
            ->get(['driver_id', 'amount'])
            ->groupBy('driver_id')
            ->map(fn ($rows): int => (int) $rows->first()->amount)
            ->all();

        $statuses = [];

        foreach ($driverIds as $driverId) {
            $statuses[$driverId] = $statement->statusFor(
                $declared[$driverId] ?? 0,
                $references[$driverId] ?? null,
                $period,
            );
        }

        return $statuses;
    }
}
