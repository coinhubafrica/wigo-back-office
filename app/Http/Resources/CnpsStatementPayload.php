<?php

namespace App\Http\Resources;

use App\Models\CnpsDeclaration;
use App\Models\Driver;
use App\Services\Cnps\CnpsStatementService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Relevé de cotisations tel que l'écran « Cotisations CNPS » l'affiche.
 *
 * Classe simple, volontairement pas une `JsonResource` avec `@mixin` : le
 * relevé est une composition, pas la projection d'un modèle. Sa forme est
 * publiée dans `docs/api/paths/cnps.yaml`.
 */
class CnpsStatementPayload
{
    /**
     * @param  int  $months  profondeur de l'historique, mois en cours compris
     * @return array<string, mixed>
     */
    public static function build(Driver $driver, CnpsStatementService $service, int $months = 13): array
    {
        $periods = $service->recentPeriods($months);
        $current = $periods[0];

        $totals = $service->declaredTotals($driver, $periods);
        $declarations = $service->declarationsByPeriod($driver, $periods);
        $reference = $service->currentReference($driver);

        $months = array_map(
            fn (string $period): array => self::month(
                $period,
                $totals[$period] ?? 0,
                $service->referenceFor($driver, $period)?->amount,
                $declarations->get($period),
                $service,
            ),
            $periods,
        );

        return [
            'reference' => $reference === null ? null : [
                'amount' => $reference->amount,
                'effective_from' => $reference->effective_from->toDateString(),
                /** @var 'driver'|'agent' */
                'set_by' => $reference->set_by->value,
            ],
            'current' => $months[0],
            // L'historique exclut le mois en cours : l'écran le montre déjà
            // dans la carte du haut. Du plus récent au plus ancien.
            'history' => array_slice($months, 1),
        ];
    }

    /**
     * @param  EloquentCollection<int, CnpsDeclaration>|null  $declarations
     * @return array<string, mixed>
     */
    private static function month(
        string $period,
        int $declared,
        ?int $reference,
        ?EloquentCollection $declarations,
        CnpsStatementService $service,
    ): array {
        return [
            'period' => $period,
            'label' => $service->labelFor($period),
            'reference_amount' => $reference,
            'declared_amount' => $declared,
            'remaining' => $service->remainingFor($declared, $reference),
            'progress' => $service->progressFor($declared, $reference),
            /** @var 'paid'|'partial'|'late'|'pending' */
            'status' => $service->statusFor($declared, $reference, $period)->value,
            'declarations' => $declarations === null
                ? []
                : $declarations->map(fn (CnpsDeclaration $declaration): array => [
                    'id' => $declaration->id,
                    'declared_amount' => $declaration->declared_amount,
                    'payment_date' => $declaration->payment_date->toDateString(),
                    // Le relevé couvre treize mois : signer une URL par
                    // justificatif coûterait cher pour une icône. L'application
                    // demande l'URL au moment d'ouvrir la pièce.
                    'has_proof' => $declaration->proof_path !== null,
                ])->values()->all(),
        ];
    }
}
