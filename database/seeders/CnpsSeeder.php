<?php

namespace Database\Seeders;

use App\Enums\CnpsReferenceSetter;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
use App\Models\Driver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Relevé de cotisations du conducteur nominal, calqué sur l'écran mobile
 * « Cotisations CNPS » : référence à 9 000, mois en cours à moitié réglé, cinq
 * mois soldés, un partiel, trois en retard.
 *
 * Les mois sont relatifs à la date d'exécution — le jeu d'essai reste
 * ressemblant quel que soit le moment où on le rejoue.
 */
class CnpsSeeder extends Seeder
{
    private const REFERENCE_AMOUNT = 9000;

    public function run(): void
    {
        $driver = Driver::query()->where('phone', '+2250717738299')->first();

        if ($driver === null) {
            return;
        }

        // Idempotent : on repart d'un relevé vierge pour ce conducteur plutôt
        // que d'empiler des versements à chaque exécution.
        CnpsDeclaration::query()->where('driver_id', $driver->id)->delete();
        CnpsReference::query()->where('driver_id', $driver->id)->delete();

        $current = Carbon::now()->startOfMonth();

        CnpsReference::query()->create([
            'driver_id' => $driver->id,
            'amount' => self::REFERENCE_AMOUNT,
            'effective_from' => $current->copy()->subMonths(11),
            'set_by' => CnpsReferenceSetter::Driver,
        ]);

        // Mois en cours : 6 000 sur 9 000, réglés en deux fois — la carte du
        // haut affiche « Partiel », 67 %.
        $this->declare($driver, $current, 3000, day: 4);
        $this->declare($driver, $current, 3000, day: 18);

        // Les cinq mois précédents, soldés d'un seul versement.
        foreach (range(1, 5) as $monthsAgo) {
            $this->declare($driver, $current->copy()->subMonths($monthsAgo), self::REFERENCE_AMOUNT, day: 3);
        }

        // Puis un mois partiel, et trois mois sans rien : « En retard ».
        $this->declare($driver, $current->copy()->subMonths(6), 6000, day: 9);
    }

    private function declare(Driver $driver, Carbon $period, int $amount, int $day): void
    {
        $paidOn = $period->copy()->setDay($day);

        CnpsDeclaration::query()->create([
            'driver_id' => $driver->id,
            'period' => $period->format('Y-m'),
            'declared_amount' => $amount,
            'payment_date' => $paidOn,
            'proof_path' => null,
            'declared_at' => $paidOn,
        ]);
    }
}
