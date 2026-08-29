<?php

namespace App\Services\Fleet;

use App\Contracts\FleetClient;
use App\Models\Driver;
use Illuminate\Support\Facades\Log;

/**
 * Doublure locale : grand livre en mémoire, aucune sortie réseau.
 *
 * `failNext()` force l'échec du prochain crédit — c'est ce qui rend testable
 * le chemin « Wave a encaissé, Yango a refusé », donc la bascule en « à
 * vérifier » et le bouton « Rejouer » du back-office.
 */
class FakeFleetClient implements FleetClient
{
    /** @var array<string, int> */
    private array $balances = [];

    /** @var list<array{driver_id: string, amount: int, reference: string}> */
    private array $credits = [];

    private bool $failNext = false;

    public function creditWallet(Driver $driver, int $amount, string $reference): bool
    {
        if ($this->failNext) {
            $this->failNext = false;

            Log::info('Fleet (local) : crédit refusé volontairement', ['reference' => $reference]);

            return false;
        }

        $key = (string) $driver->getKey();
        $this->balances[$key] = ($this->balances[$key] ?? 0) + $amount;
        $this->credits[] = ['driver_id' => $key, 'amount' => $amount, 'reference' => $reference];

        Log::info('Fleet (local) : solde crédité', [
            'reference' => $reference,
            'amount' => $amount,
            'balance' => $this->balances[$key],
        ]);

        return true;
    }

    public function balanceFor(Driver $driver): ?int
    {
        return $this->balances[(string) $driver->getKey()] ?? 0;
    }

    /**
     * Fait échouer le prochain crédit, une seule fois.
     */
    public function failNext(): void
    {
        $this->failNext = true;
    }

    /**
     * Crédits enregistrés, pour vérifier dans les tests qu'un règlement rejoué
     * n'a bien crédité qu'une fois.
     *
     * @return list<array{driver_id: string, amount: int, reference: string}>
     */
    public function credits(): array
    {
        return $this->credits;
    }

    public function setBalance(Driver $driver, int $balance): void
    {
        $this->balances[(string) $driver->getKey()] = $balance;
    }
}
