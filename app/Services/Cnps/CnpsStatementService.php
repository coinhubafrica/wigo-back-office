<?php

namespace App\Services\Cnps;

use App\Enums\CnpsMonthStatus;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Relevé de cotisations d'un conducteur : ce qu'il a déclaré, face au montant
 * qu'il visait.
 *
 * Les deux tables n'ont aucun lien direct — elles se rejoignent ici, sur le
 * mois. Le montant de référence appartient au mois, pas au versement : deux
 * versements d'août partagent le même, et un reliquat réglé en septembre reste
 * jugé au montant d'août.
 */
class CnpsStatementService
{
    /**
     * Montant de référence en vigueur à la fin d'un mois donné.
     *
     * Renvoie null tant que le conducteur n'en a jamais fixé : sans repère,
     * aucun mois ne peut être déclaré « en retard ».
     */
    public function referenceFor(Driver $driver, string $period): ?CnpsReference
    {
        return CnpsReference::query()
            ->where('driver_id', $driver->id)
            ->where('effective_from', '<=', $this->endOfPeriod($period))
            ->latestFirst()
            ->first();
    }

    /**
     * Dernier montant fixé, quel que soit le mois — celui que l'application
     * affiche comme « montant mensuel de référence ».
     */
    public function currentReference(Driver $driver): ?CnpsReference
    {
        return CnpsReference::query()
            ->where('driver_id', $driver->id)
            ->latestFirst()
            ->first();
    }

    /**
     * Cumul déclaré par mois, sur les périodes demandées.
     *
     * Une seule requête agrégée pour toute la fenêtre : le relevé couvre
     * treize mois, un aller-retour par mois serait inutile.
     *
     * @param  list<string>  $periods
     * @return array<string, int> période => total déclaré
     */
    public function declaredTotals(Driver $driver, array $periods): array
    {
        if ($periods === []) {
            return [];
        }

        return CnpsDeclaration::query()
            ->where('driver_id', $driver->id)
            ->whereIn('period', $periods)
            ->groupBy('period')
            ->selectRaw('period, sum(declared_amount) as aggregate')
            ->pluck('aggregate', 'period')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * Déclarations d'un conducteur sur les périodes demandées, regroupées par
     * mois et classées du versement le plus récent au plus ancien.
     *
     * @param  list<string>  $periods
     * @return Collection<int|string, EloquentCollection<int, CnpsDeclaration>>
     */
    public function declarationsByPeriod(Driver $driver, array $periods): Collection
    {
        if ($periods === []) {
            return new Collection;
        }

        return CnpsDeclaration::query()
            ->where('driver_id', $driver->id)
            ->whereIn('period', $periods)
            ->orderByDesc('payment_date')
            ->orderByDesc('declared_at')
            ->get()
            ->groupBy('period');
    }

    /**
     * État d'un mois, déduit — jamais stocké.
     *
     * Le mois en cours n'est jamais « en retard » : le conducteur a encore le
     * temps de payer.
     */
    public function statusFor(int $declared, ?int $reference, string $period): CnpsMonthStatus
    {
        if ($declared > 0 && $reference !== null && $reference > 0 && $declared >= $reference) {
            return CnpsMonthStatus::Paid;
        }

        if ($declared > 0) {
            return CnpsMonthStatus::Partial;
        }

        return $this->isPast($period) ? CnpsMonthStatus::Late : CnpsMonthStatus::Pending;
    }

    /**
     * Avancement en pourcentage entier, plafonné à 100 : déclarer plus que
     * prévu affiche 100 %, pas 133 %.
     *
     * Arrondi au plus proche, comme la maquette : 6 000 sur 9 000 s'affiche
     * « 67 % », pas 66.
     */
    public function progressFor(int $declared, ?int $reference): int
    {
        if ($reference === null || $reference <= 0) {
            return $declared > 0 ? 100 : 0;
        }

        return (int) min(100, round($declared * 100 / $reference));
    }

    /**
     * Reste à déclarer, jamais négatif.
     */
    public function remainingFor(int $declared, ?int $reference): int
    {
        if ($reference === null) {
            return 0;
        }

        return max(0, $reference - $declared);
    }

    /**
     * Les `$months` derniers mois, du plus récent au plus ancien, mois en
     * cours inclus en première position.
     *
     * @return list<string>
     */
    public function recentPeriods(int $months): array
    {
        $current = Carbon::now()->startOfMonth();

        return array_map(
            fn (int $offset): string => $current->copy()->subMonths($offset)->format('Y-m'),
            range(0, $months - 1),
        );
    }

    /**
     * Libellé d'un mois tel que l'application l'affiche : « Août 2026 ».
     *
     * Locale forcée : le relevé est destiné à des conducteurs ivoiriens, il ne
     * doit pas basculer en anglais si `APP_LOCALE` change. Carbon rend « août »
     * en minuscule, l'écran l'affiche capitalisé.
     */
    public function labelFor(string $period): string
    {
        // `settings()` plutôt que `locale()` : cette dernière est un accesseur
        // autant qu'un mutateur et se type `Carbon|string`.
        return Str::ucfirst(
            $this->startOfPeriod($period)
                ->settings(['locale' => 'fr'])
                ->translatedFormat('F Y'),
        );
    }

    public function currentPeriod(): string
    {
        return Carbon::now()->format('Y-m');
    }

    private function isPast(string $period): bool
    {
        return $period < $this->currentPeriod();
    }

    private function endOfPeriod(string $period): Carbon
    {
        return $this->startOfPeriod($period)->endOfMonth();
    }

    /**
     * Premier jour d'un mois « 2026-08 ».
     */
    private function startOfPeriod(string $period): Carbon
    {
        [$year, $month] = explode('-', $period);

        return Carbon::create((int) $year, (int) $month, 1)->startOfDay();
    }
}
