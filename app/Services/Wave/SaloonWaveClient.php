<?php

namespace App\Services\Wave;

use App\Contracts\WaveClient;
use App\Enums\TransactionType;
use App\Http\Integrations\Wave\Exceptions\WaveException;
use App\Http\Integrations\Wave\Requests\CreateCheckoutSessionRequest;
use App\Http\Integrations\Wave\Requests\GetBalanceRequest;
use App\Http\Integrations\Wave\WaveConnector;
use App\Models\Transaction;
use App\Settings\WaveAccount;
use App\Settings\WaveAccountSettings;
use Illuminate\Support\Facades\Log;

/**
 * Appels réels à Wave, via Saloon.
 *
 * Deux comptes : le compte visé se déduit du type de transaction (une commande
 * encaisse sur la boutique, une recharge sur le compte Yango) plutôt que d'être
 * passé par l'appelant, pour qu'aucun chemin de code ne puisse encaisser une
 * commande sur le mauvais compte par omission d'argument.
 *
 * Aucune exception ne remonte : un fournisseur muet rend `null`, et c'est
 * l'appelant qui décide quoi en faire (422 côté mobile, « — » côté écran).
 */
class SaloonWaveClient implements WaveClient
{
    public function createCheckoutSession(Transaction $transaction): ?WaveCheckoutSession
    {
        $account = self::accountFor($transaction->type);
        $settings = $account->settings();

        if (! $settings->isConfigured()) {
            Log::warning('Wave : compte non configuré', [
                'reference' => $transaction->reference,
                'account' => $account->value,
            ]);

            return null;
        }

        try {
            $response = $this->connector($settings)->send(new CreateCheckoutSessionRequest(
                $transaction->amount,
                $transaction->currency,
                $transaction->reference,
                route('wave.success'),
                route('wave.error'),
            ));
        } catch (WaveException $exception) {
            Log::warning('Wave : ouverture de session refusée', [
                'reference' => $transaction->reference,
                'account' => $account->value,
                'status' => $exception->getStatusCode(),
            ]);

            return null;
        }

        $id = $response->json('id');
        $launchUrl = $response->json('wave_launch_url');

        if (! is_string($id) || ! is_string($launchUrl)) {
            Log::warning('Wave : réponse inexploitable', [
                'reference' => $transaction->reference,
                'account' => $account->value,
            ]);

            return null;
        }

        return new WaveCheckoutSession($id, $launchUrl);
    }

    public function verifySignature(WaveAccount $account, string $payload, ?string $signature): bool
    {
        $secret = $account->settings()->webhook_secret;

        if ($secret === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    public function businessBalance(WaveAccount $account): ?int
    {
        $settings = $account->settings();

        if (! $settings->isConfigured()) {
            return null;
        }

        try {
            $response = $this->connector($settings)->send(new GetBalanceRequest);
        } catch (WaveException $exception) {
            Log::warning('Wave : solde indisponible', [
                'account' => $account->value,
                'status' => $exception->getStatusCode(),
            ]);

            return null;
        }

        $amount = $response->json('amount');

        return is_numeric($amount) ? (int) $amount : null;
    }

    /**
     * Compte à débiter pour un type de mouvement.
     *
     * Les types non encore basculés sur Wave (CNPS, bonus) tombent sur la
     * boutique, compte « général » du back-office.
     */
    public static function accountFor(TransactionType $type): WaveAccount
    {
        return match ($type) {
            TransactionType::Recharge => WaveAccount::Topup,
            default => WaveAccount::Shop,
        };
    }

    private function connector(WaveAccountSettings $settings): WaveConnector
    {
        return new WaveConnector($settings->api_key);
    }
}
