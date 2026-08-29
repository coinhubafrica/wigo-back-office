<?php

namespace Database\Seeders;

use App\Enums\TransactionProvider;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Driver;
use App\Models\Transaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Journal des transactions Wave, calqué sur l'écran « Paiements » du prototype :
 * des recharges créditées dans la journée, deux en attente et un échec — de
 * quoi remplir les quatre cartes de tête et rendre les deux boutons d'action
 * atteignables en local, sans jamais appeler Wave.
 *
 * Les horodatages sont relatifs à l'exécution : « encaissé aujourd'hui » reste
 * vrai quel que soit le jour où on rejoue le jeu d'essai.
 */
class RechargeSeeder extends Seeder
{
    /**
     * Montants et états repris du prototype (`transactions` de la classe JS).
     *
     * @var list<array{phone: string, amount: int, status: TransactionStatus, hours_ago: int}>
     */
    private const FIXTURES = [
        ['phone' => '+2250717738299', 'amount' => 12500, 'status' => TransactionStatus::Initiated, 'hours_ago' => 1],
        ['phone' => '+2250700000007', 'amount' => 10000, 'status' => TransactionStatus::Paid, 'hours_ago' => 2],
        // Encaissée par Wave, refusée par Yango : la ligne à rejouer.
        ['phone' => '+2250700000004', 'amount' => 5000, 'status' => TransactionStatus::ToReview, 'hours_ago' => 3],
        ['phone' => '+2250700000003', 'amount' => 15000, 'status' => TransactionStatus::Credited, 'hours_ago' => 4],
        ['phone' => '+2250717738299', 'amount' => 10000, 'status' => TransactionStatus::Credited, 'hours_ago' => 5],
        ['phone' => '+2250700000007', 'amount' => 5000, 'status' => TransactionStatus::Credited, 'hours_ago' => 6],
        ['phone' => '+2250717738299', 'amount' => 5000, 'status' => TransactionStatus::Failed, 'hours_ago' => 26],
        ['phone' => '+2250700000004', 'amount' => 20000, 'status' => TransactionStatus::Credited, 'hours_ago' => 30],
    ];

    public function run(): void
    {
        $drivers = Driver::query()
            ->whereIn('phone', array_column(self::FIXTURES, 'phone'))
            ->get()
            ->keyBy('phone');

        if ($drivers->isEmpty()) {
            return;
        }

        $year = Carbon::now()->year;
        $sequence = 0;

        foreach (self::FIXTURES as $fixture) {
            $driver = $drivers->get($fixture['phone']);

            if ($driver === null) {
                continue;
            }

            $sequence++;
            $initiatedAt = Carbon::now()->subHours($fixture['hours_ago']);
            $status = $fixture['status'];

            // Idempotent : la référence porte le rang du jeu d'essai, une
            // seconde exécution réécrit la même ligne au lieu d'en ajouter.
            Transaction::query()->updateOrCreate(
                ['reference' => sprintf('RCH-%d-%04d', $year, $sequence)],
                [
                    'driver_id' => $driver->getKey(),
                    'type' => TransactionType::Recharge,
                    'provider' => TransactionProvider::Wave,
                    'status' => $status,
                    'label' => 'Recharge YANGO PRO',
                    'subtitle' => 'Paiement Wave',
                    'amount' => $fixture['amount'],
                    'sign' => 1,
                    'currency' => 'XOF',
                    'external_reference' => $status === TransactionStatus::Initiated
                        ? null
                        : 'cos-'.str_pad((string) $sequence, 10, '0', STR_PAD_LEFT),
                    'checkout_url' => $status->awaitsCredit()
                        ? 'https://pay.wave.com/fake/'.sprintf('RCH-%d-%04d', $year, $sequence)
                        : null,
                    'initiated_at' => $initiatedAt,
                    'paid_at' => $status === TransactionStatus::Initiated ? null : $initiatedAt->copy()->addMinutes(2),
                    'settled_at' => $status === TransactionStatus::Credited ? $initiatedAt->copy()->addMinutes(3) : null,
                    'failure_reason' => match ($status) {
                        TransactionStatus::ToReview => 'Crédit du solde Yango refusé',
                        TransactionStatus::Failed => 'Paiement abandonné',
                        default => null,
                    },
                ],
            );
        }

        $this->command->info(sprintf('RechargeSeeder : %d transactions Wave.', $sequence));
    }
}
