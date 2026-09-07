<?php

namespace App\Livewire\Cnps;

use App\Enums\BackOfficeModule;
use App\Enums\CnpsMonthStatus;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
use App\Models\Driver;
use App\Services\Cnps\CnpsStatementService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Suivi des cotisations RSTI, mois par mois.
 *
 * Pas une file de validation : une déclaration n'a pas de statut, rien n'est à
 * approuver. « Seuls les états de la CNPS font foi. » L'agent constate, relance
 * et corrige au besoin le montant de référence.
 *
 * La liste part des conducteurs, pas des déclarations : un mois « en retard »
 * est justement un mois sans ligne, il n'apparaîtrait jamais autrement.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Cnps])]
class Index extends Component
{
    use WithPagination;

    /**
     * Profondeur sur laquelle un excédent se reporte, mois affiché compris.
     */
    private const CARRY_WINDOW_MONTHS = 13;

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $state = null;

    #[Url]
    public string $period = '';

    public function mount(CnpsStatementService $statement): void
    {
        if ($this->period === '') {
            $this->period = $statement->currentPeriod();
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingPeriod(): void
    {
        $this->resetPage();
    }

    public function filterByState(?string $state): void
    {
        $this->state = $state;
        $this->resetPage();
    }

    public function resetFilters(CnpsStatementService $statement): void
    {
        $this->search = '';
        $this->state = null;
        $this->period = $statement->currentPeriod();
        $this->resetPage();
    }

    /**
     * Les douze derniers mois, pour le sélecteur de période.
     *
     * @return array<string, string>
     */
    public function periodOptions(CnpsStatementService $statement): array
    {
        $options = [];

        foreach ($statement->recentPeriods(12) as $period) {
            $options[$period] = $statement->labelFor($period);
        }

        return $options;
    }

    public function render(CnpsStatementService $statement): View
    {
        $rows = $this->rows($statement);

        /** @var view-string $view */
        $view = 'livewire.cnps.index';

        return view($view, [
            'rows' => $rows,
            'allocations' => $this->allocations($rows->getCollection(), $statement),
            'periodLabel' => $statement->labelFor($this->period),
            'periodOptions' => $this->periodOptions($statement),
            'totals' => $this->totals($statement),
        ]);
    }

    /**
     * Une ligne par conducteur pour le mois choisi : ce qu'il a déclaré, face
     * au montant qu'il visait alors.
     *
     * @return LengthAwarePaginator<int, Driver>
     */
    private function rows(CnpsStatementService $statement): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Driver> $drivers */
        $drivers = $this->baseQuery($statement)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20);

        return $drivers;
    }

    /**
     * Cumul déclaré par un conducteur sur le mois affiché.
     *
     * @return Builder<CnpsDeclaration>
     */
    private function declaredSubQuery(): Builder
    {
        return CnpsDeclaration::query()
            ->selectRaw('coalesce(sum(declared_amount), 0)')
            ->whereColumn('cnps_declarations.driver_id', 'drivers.id')
            ->where('period', $this->period);
    }

    /**
     * Montant imputé au mois affiché, report des mois antérieurs compris.
     *
     * Le report se calcule sur la chronologie complète, ce qu'une sous-requête
     * par mois ne peut pas faire : le cumul est donc reconstitué en PHP à
     * partir des déclarations et des références de la fenêtre, puis rapporté
     * sur les lignes de la page.
     *
     * @param  Collection<int, Driver>  $drivers
     * @return array<string, array{applied: int, carry_in: int, carry_out: int}>
     */
    private function allocations(Collection $drivers, CnpsStatementService $statement): array
    {
        if ($drivers->isEmpty()) {
            return [];
        }

        $driverIds = $drivers->pluck('id')->all();
        $periods = $this->periodsUpTo();

        $totals = CnpsDeclaration::query()
            ->whereIn('driver_id', $driverIds)
            ->whereIn('period', $periods)
            ->groupBy('driver_id', 'period')
            ->selectRaw('driver_id, period, sum(declared_amount) as aggregate')
            ->get()
            ->groupBy('driver_id');

        $references = CnpsReference::query()
            ->whereIn('driver_id', $driverIds)
            ->where('effective_from', '<=', $this->endOfPeriod())
            ->orderBy('effective_from')
            ->orderBy('created_at')
            ->get()
            ->groupBy('driver_id');

        $allocations = [];

        foreach ($drivers as $driver) {
            $driverTotals = $totals->get($driver->id, new Collection)
                ->mapWithKeys(fn (CnpsDeclaration $row): array => [$row->period => (int) $row->aggregate])
                ->all();

            $driverReferences = $this->referencesByPeriod(
                $references->get($driver->id, new Collection),
                $periods,
            );

            $allocations[$driver->id] = $statement
                ->allocateWithCarryOver($periods, $driverTotals, $driverReferences)[$this->period];
        }

        return $allocations;
    }

    /**
     * Référence en vigueur pour chaque mois de la fenêtre, depuis une liste
     * déjà chargée et triée par date d'effet croissante.
     *
     * @param  Collection<int, CnpsReference>  $references
     * @param  list<string>  $periods
     * @return array<string, int|null>
     */
    private function referencesByPeriod(Collection $references, array $periods): array
    {
        $resolved = [];

        foreach ($periods as $period) {
            [$year, $month] = explode('-', $period);
            $end = Carbon::create((int) $year, (int) $month, 1)->endOfMonth();

            $inForce = $references->last(
                fn (CnpsReference $reference): bool => $reference->effective_from <= $end,
            );

            $resolved[$period] = $inForce?->amount;
        }

        return $resolved;
    }

    /**
     * La fenêtre sur laquelle le report se propage : les douze mois précédant
     * le mois affiché, plus lui-même.
     *
     * Douze mois suffisent — au-delà, une avance aurait été absorbée depuis
     * longtemps, et remonter à l'origine du conducteur coûterait une requête
     * sans borne.
     *
     * @return list<string>
     */
    private function periodsUpTo(): array
    {
        [$year, $month] = explode('-', $this->period);
        $end = Carbon::create((int) $year, (int) $month, 1);

        $periods = array_map(
            fn (int $offset): string => $end->copy()->subMonths($offset)->format('Y-m'),
            range(self::CARRY_WINDOW_MONTHS - 1, 0),
        );

        return array_values($periods);
    }

    /**
     * Dernier montant fixé avant la fin du mois affiché : c'est lui qui juge
     * ce mois-là, même s'il a changé depuis.
     */
    private function referenceSubQuery(): QueryBuilder
    {
        return DB::table('cnps_references')
            ->select('amount')
            ->whereColumn('cnps_references.driver_id', 'drivers.id')
            ->where('effective_from', '<=', $this->endOfPeriod()->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->limit(1);
    }

    /**
     * Nombre de versements enregistrés sur le mois affiché.
     *
     * @return Builder<CnpsDeclaration>
     */
    private function paymentCountSubQuery(): Builder
    {
        return CnpsDeclaration::query()
            ->selectRaw('count(*)')
            ->whereColumn('cnps_declarations.driver_id', 'drivers.id')
            ->where('period', $this->period);
    }

    /**
     * Nombre de versements accompagnés d'un justificatif.
     *
     * @return Builder<CnpsDeclaration>
     */
    private function proofCountSubQuery(): Builder
    {
        return $this->paymentCountSubQuery()->whereNotNull('proof_path');
    }

    /**
     * État d'un mois pour une ligne du tableau, déduit comme côté mobile.
     *
     * `$applied` est le montant imputé au mois — report des mois antérieurs
     * compris — et non la seule somme déclarée sur ce mois.
     */
    public function statusOf(int $applied, ?int $reference): CnpsMonthStatus
    {
        return app(CnpsStatementService::class)->statusFor($applied, $reference, $this->period);
    }

    /**
     * Conducteurs du mois, avec le cumul déclaré et la référence en vigueur
     * rapportés en colonnes calculées.
     *
     * @return Builder<Driver>
     */
    private function baseQuery(CnpsStatementService $statement): Builder
    {
        $query = Driver::query()
            ->select('drivers.*')
            ->selectSub($this->declaredSubQuery(), 'period_declared')
            ->selectSub($this->referenceSubQuery(), 'period_reference')
            ->selectSub($this->paymentCountSubQuery(), 'period_payments')
            ->selectSub($this->proofCountSubQuery(), 'period_proofs')
            ->when($this->search !== '', function (Builder $query): void {
                $term = "%{$this->search}%";
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('yango_id', 'like', $term);
                });
            });

        if ($this->state === null) {
            return $query;
        }

        // L'état ne se compare plus en SQL : depuis que l'excédent d'un mois
        // solde le suivant, il dépend de toute la chronologie du conducteur, pas
        // des deux colonnes de ce mois-ci. Un filtre SQL et une pastille rendue
        // en PHP se contrediraient — le mois soldé par une avance s'afficherait
        // « Payé » tout en tombant dans « En retard ».
        //
        // On résout donc l'état une fois, hors pagination, et on restreint sur
        // les identifiants retenus.
        //
        // `withoutGlobalScopes` sur l'enveloppe : le filtre de suppression
        // douce s'applique déjà à l'intérieur, et qualifié `drivers.` il ne
        // résoudrait pas contre l'alias `monthly`.
        return Driver::query()
            ->withoutGlobalScopes()
            ->fromSub($query, 'monthly')
            ->whereIn('id', $this->idsMatchingState($query, $statement));
    }

    /**
     * Identifiants des conducteurs dont le mois affiché est dans l'état filtré,
     * report compris.
     *
     * @param  Builder<Driver>  $query  requête déjà filtrée par la recherche
     * @return list<string>
     */
    private function idsMatchingState(Builder $query, CnpsStatementService $statement): array
    {
        /** @var Collection<int, Driver> $candidates */
        $candidates = (clone $query)->get();

        $allocations = $this->allocations($candidates, $statement);

        return $candidates
            ->filter(function (Driver $driver) use ($allocations, $statement): bool {
                $applied = $allocations[$driver->id]['applied'] ?? 0;
                $reference = $driver->period_reference === null
                    ? null
                    : (int) $driver->period_reference;

                return $statement->statusFor($applied, $reference, $this->period)->value === $this->state;
            })
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Cartes de tête : ce que le mois pèse, et combien de conducteurs restent
     * à relancer.
     *
     * @return array{declared: int, drivers_declaring: int, behind: int}
     */
    private function totals(CnpsStatementService $statement): array
    {
        $declarations = CnpsDeclaration::query()->where('period', $this->period);

        $declaringDrivers = (clone $declarations)->distinct()->count('driver_id');

        return [
            'declared' => (int) (clone $declarations)->sum('declared_amount'),
            'drivers_declaring' => $declaringDrivers,
            // Conducteurs actifs n'ayant rien déclaré ce mois-là : la relance.
            'behind' => $this->period === $statement->currentPeriod()
                ? 0
                : Driver::query()
                    ->whereDoesntHave('cnpsDeclarations', fn (Builder $query) => $query->where('period', $this->period))
                    ->count(),
        ];
    }

    private function endOfPeriod(): Carbon
    {
        [$year, $month] = explode('-', $this->period);

        return Carbon::create((int) $year, (int) $month, 1)->endOfMonth();
    }
}
