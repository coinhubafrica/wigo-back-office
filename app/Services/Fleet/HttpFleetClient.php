<?php

namespace App\Services\Fleet;

use App\Contracts\FleetClient;
use App\Models\Driver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Appels réels à l'API Yango Fleet.
 *
 * Amorce : les chemins d'API restent à confirmer avec Yango. Tant que rien
 * n'est configuré, chaque appel journalise et rend un échec franc — jamais un
 * faux succès, qui ferait croire à un conducteur que son solde est crédité.
 */
class HttpFleetClient implements FleetClient
{
    public function creditWallet(Driver $driver, int $amount, string $reference): bool
    {
        if (! $this->isConfigured() || $driver->yango_id === null) {
            Log::warning('Fleet : crédit impossible', [
                'reference' => $reference,
                'driver' => $driver->getKey(),
                'reason' => $driver->yango_id === null ? 'conducteur sans yango_id' : 'API non configurée',
            ]);

            return false;
        }

        $response = Http::withToken((string) config('services.fleet.api_key'))
            ->timeout(20)
            ->post((string) config('services.fleet.base_url').'/v1/parks/driver-profiles/balance', [
                'park_id' => config('services.fleet.park_id'),
                'driver_profile_id' => $driver->yango_id,
                'amount' => $amount,
                'currency' => 'XOF',
                'reference' => $reference,
            ]);

        if ($response->failed()) {
            Log::error('Fleet : crédit refusé', [
                'reference' => $reference,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }

    public function balanceFor(Driver $driver): ?int
    {
        if (! $this->isConfigured() || $driver->yango_id === null) {
            return null;
        }

        $response = Http::withToken((string) config('services.fleet.api_key'))
            ->timeout(10)
            ->get((string) config('services.fleet.base_url').'/v1/parks/driver-profiles/balance', [
                'park_id' => config('services.fleet.park_id'),
                'driver_profile_id' => $driver->yango_id,
            ]);

        if ($response->failed()) {
            Log::warning('Fleet : solde indisponible', ['driver' => $driver->getKey()]);

            return null;
        }

        $balance = $response->json('balance');

        return is_numeric($balance) ? (int) $balance : null;
    }

    private function isConfigured(): bool
    {
        return filled(config('services.fleet.base_url'));
    }
}
