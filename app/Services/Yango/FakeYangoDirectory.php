<?php

namespace App\Services\Yango;

use App\Contracts\YangoDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use Generator;

/**
 * Doublure locale : le parc tient en mémoire, aucune sortie réseau.
 *
 * `failWith()` fait lever la prochaine passe — c'est ce qui rend testable le
 * chemin « Yango répond 401 » (le job échoue franchement) face à « Yango est
 * en panne » (le job réessaie), sans jamais monter de faux serveur HTTP.
 */
class FakeYangoDirectory implements YangoDirectory
{
    /** @var list<array<string, mixed>> */
    private array $drivers = [];

    /** @var list<array<string, mixed>> */
    private array $vehicles = [];

    private ?YangoFleetException $failure = null;

    public function drivers(int $pageSize = 100): Generator
    {
        $this->guard();

        yield from $this->drivers;
    }

    public function vehicles(int $pageSize = 100): Generator
    {
        $this->guard();

        yield from $this->vehicles;
    }

    /**
     * @param  list<array<string, mixed>>  $drivers
     */
    public function setDrivers(array $drivers): void
    {
        $this->drivers = $drivers;
    }

    /**
     * @param  list<array<string, mixed>>  $vehicles
     */
    public function setVehicles(array $vehicles): void
    {
        $this->vehicles = $vehicles;
    }

    public function failWith(YangoFleetException $exception): void
    {
        $this->failure = $exception;
    }

    private function guard(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
