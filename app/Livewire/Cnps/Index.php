<?php

namespace App\Livewire\Cnps;

use App\Enums\BackOfficeModule;
use App\Enums\CnpsMonthStatus;
use App\Models\CnpsDeclaration;
use App\Models\Driver;
use App\Services\Cnps\CnpsStatementService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
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
     * Dernier montant fixé avant la fin du mois affiché : c'est lui qui juge
     * ce mois-là, même s'il a changé depuis.
     */
    private function referenceSubQuery(): QueryBuilder
    {
        return DB::table('cnps_references')
            ->select('amount')
            ->whereColumn('cnps_references.driver_id', 'drivers.id')
            ->where('effective_from', '<=', $this->endOfPeriod())
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
     */
    public function statusOf(int $declared, ?int $reference): CnpsMonthStatus
    {
        return app(CnpsStatementService::class)->statusFor($declared, $reference, $this->period);
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

        // L'état se déduit des deux colonnes calculées : on enveloppe la
        // requête pour pouvoir les comparer par leur alias, plutôt que de
        // recopier leur SQL dans un `whereRaw`.
        //
        // `withoutGlobalScopes` sur l'enveloppe : le filtre de suppression
        // douce s'applique déjà à l'intérieur, et qualifié `drivers.` il ne
        // résoudrait pas contre l'alias `monthly`.
        return Driver::query()
            ->withoutGlobalScopes()
            ->fromSub($query, 'monthly')
            ->tap(fn (Builder $wrapped) => $this->constrainToState($wrapped, $statement));
    }

    /**
     * Restreint la requête enveloppée à un état, par comparaison des colonnes
     * calculées.
     *
     * @param  Builder<Driver>  $query
     */
    private function constrainToState(Builder $query, CnpsStatementService $statement): void
    {
        $isCurrent = $this->period === $statement->currentPeriod();

        match ($this->state) {
            CnpsMonthStatus::Paid->value => $query
                ->where('period_declared', '>', 0)
                ->whereNotNull('period_reference')
                ->whereColumn('period_declared', '>=', 'period_reference'),
            CnpsMonthStatus::Partial->value => $query
                ->where('period_declared', '>', 0)
                ->where(function (Builder $query): void {
                    $query->whereNull('period_reference')
                        ->orWhereColumn('period_declared', '<', 'period_reference');
                }),
            // Un mois vide n'est « en retard » que s'il est révolu ; le mois en
            // cours reste simplement « à déclarer ».
            CnpsMonthStatus::Late->value => $isCurrent
                ? $query->whereRaw('1 = 0')
                : $query->where('period_declared', 0),
            CnpsMonthStatus::Pending->value => $isCurrent
                ? $query->where('period_declared', 0)
                : $query->whereRaw('1 = 0'),
            default => null,
        };
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

    private function endOfPeriod(): string
    {
        [$year, $month] = explode('-', $this->period);

        return Carbon::create((int) $year, (int) $month, 1)
            ->endOfMonth()
            ->toDateString();
    }
}
