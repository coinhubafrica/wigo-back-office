<?php

namespace App\Services\Yango;

use App\Contracts\YangoClient;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\CreateDriverTransactionRequest;
use App\Http\Integrations\Yango\Requests\GetDriverBalanceRequest;
use App\Http\Integrations\Yango\YangoFleetConnector;
use App\Models\Driver;
use App\Settings\YangoSettings;
use Illuminate\Support\Facades\Log;

/**
 * Solde et crédit conducteur, via l'API Yango Fleet.
 *
 * Mêmes identifiants que `SaloonYangoDirectory` — les réglages en base, résolus
 * à l'appel — pour qu'une clé corrigée à l'écran serve aux deux sans purge de
 * cache. Auparavant cette classe lisait `config('services.yango.*')` : deux
 * sources pour un seul parc, dont une jamais renseignée, ce qui envoyait des
 * crédits porteurs d'un jeton vide.
 *
 * Contrat d'erreur inverse de l'annuaire : ici on rend `false`/`null` plutôt que
 * de lever. Un crédit refusé bascule la transaction en « à vérifier », un solde
 * muet s'affiche « — » ; ni l'un ni l'autre ne doit faire tomber la requête.
 */
class SaloonYangoClient implements YangoClient
{
    public function creditWallet(Driver $driver, int $amount, string $reference): bool
    {
        $settings = app(YangoSettings::class);

        if (! $settings->isConfigured() || $driver->yango_id === null) {
            Log::warning('Fleet : crédit impossible', [
                'reference' => $reference,
                'driver' => $driver->getKey(),
                'reason' => $driver->yango_id === null ? 'conducteur sans yango_id' : 'API non configurée',
            ]);

            return false;
        }

        try {
            $this->connector($settings)->send(new CreateDriverTransactionRequest(
                $settings->park_id,
                $driver->yango_id,
                $amount,
                $reference,
            ));
        } catch (YangoFleetException $exception) {
            Log::error('Fleet : crédit refusé', [
                'reference' => $reference,
                'status' => $exception->getStatusCode(),
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    public function balanceFor(Driver $driver): ?int
    {
        $settings = app(YangoSettings::class);

        if (! $settings->isConfigured() || $driver->yango_id === null) {
            return null;
        }

        try {
            $response = $this->connector($settings)->send(new GetDriverBalanceRequest(
                $settings->park_id,
                $driver->yango_id,
            ));
        } catch (YangoFleetException $exception) {
            Log::warning('Fleet : solde indisponible', [
                'driver' => $driver->getKey(),
                'status' => $exception->getStatusCode(),
            ]);

            return null;
        }

        $profile = $response->json('driver_profiles.0');

        if (! is_array($profile)) {
            return null;
        }

        return $this->readBalance($profile);
    }

    /**
     * Solde du compte courant du profil.
     *
     * Yango rend une liste de comptes par conducteur ; seul celui de type
     * `current` porte le solde utilisable. Le montant arrive en chaîne
     * décimale (« 1500.0000 ») et se lit en entier de FCFA.
     *
     * @param  array<string, mixed>  $profile
     */
    private function readBalance(array $profile): ?int
    {
        $accounts = $profile['accounts'] ?? null;

        if (! is_array($accounts)) {
            return null;
        }

        foreach ($accounts as $account) {
            if (! is_array($account) || ($account['type'] ?? null) !== 'current') {
                continue;
            }

            $balance = $account['balance'] ?? null;

            return is_numeric($balance) ? (int) round((float) $balance) : null;
        }

        return null;
    }

    private function connector(YangoSettings $settings): YangoFleetConnector
    {
        return new YangoFleetConnector(
            $settings->base_url,
            $settings->park_id,
            $settings->api_key,
        );
    }
}
