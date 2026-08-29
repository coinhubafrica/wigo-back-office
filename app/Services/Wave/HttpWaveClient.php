<?php

namespace App\Services\Wave;

use App\Contracts\WaveClient;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Appels réels à Wave Checkout.
 *
 * Aucune exception ne remonte : un fournisseur muet rend `null`, et c'est
 * l'appelant qui décide quoi en faire (422 côté mobile, « — » côté écran).
 */
class HttpWaveClient implements WaveClient
{
    public function createCheckoutSession(Transaction $transaction): ?WaveCheckoutSession
    {
        $baseUrl = config('services.wave.base_url');

        if (blank($baseUrl)) {
            Log::warning('Wave : aucun fournisseur configuré', ['reference' => $transaction->reference]);

            return null;
        }

        $response = Http::withToken((string) config('services.wave.api_key'))
            ->timeout(15)
            ->post((string) $baseUrl.'/v1/checkout/sessions', [
                'amount' => (string) $transaction->amount,
                'currency' => $transaction->currency,
                // La référence lisible sert de clé de rapprochement au retour.
                'client_reference' => $transaction->reference,
                'success_url' => route('wave.success'),
                'error_url' => route('wave.error'),
            ]);

        if ($response->failed()) {
            Log::warning('Wave : ouverture de session refusée', [
                'reference' => $transaction->reference,
                'status' => $response->status(),
            ]);

            return null;
        }

        $id = $response->json('id');
        $launchUrl = $response->json('wave_launch_url');

        if (! is_string($id) || ! is_string($launchUrl)) {
            Log::warning('Wave : réponse inexploitable', ['reference' => $transaction->reference]);

            return null;
        }

        return new WaveCheckoutSession($id, $launchUrl);
    }

    public function verifySignature(string $payload, ?string $signature): bool
    {
        $secret = (string) config('services.wave.webhook_secret');

        if ($secret === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    public function businessBalance(): ?int
    {
        $baseUrl = config('services.wave.base_url');

        if (blank($baseUrl)) {
            return null;
        }

        $response = Http::withToken((string) config('services.wave.api_key'))
            ->timeout(10)
            ->get((string) $baseUrl.'/v1/balance');

        if ($response->failed()) {
            Log::warning('Wave : solde indisponible', ['status' => $response->status()]);

            return null;
        }

        $amount = $response->json('amount');

        return is_numeric($amount) ? (int) $amount : null;
    }
}
