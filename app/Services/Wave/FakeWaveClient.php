<?php

namespace App\Services\Wave;

use App\Contracts\WaveClient;
use App\Models\Transaction;
use App\Settings\WaveAccount;
use Illuminate\Support\Facades\Log;

/**
 * Doublure locale : aucune sortie réseau, sessions déterministes.
 *
 * `verifySignature()` calcule le VRAI HMAC contre le secret du compte visé
 * plutôt que de rendre `true` : les tests de signature du webhook doivent
 * éprouver l'algorithme, pas le contourner — y compris le fait qu'un compte ne
 * valide pas la signature de l'autre.
 */
class FakeWaveClient implements WaveClient
{
    /** @var list<array{reference: string, amount: int, session_id: string, account: string}> */
    private array $sessions = [];

    private bool $refuseNext = false;

    private ?int $balance = 2435000;

    public function createCheckoutSession(Transaction $transaction): ?WaveCheckoutSession
    {
        if ($this->refuseNext) {
            $this->refuseNext = false;

            return null;
        }

        $sessionId = 'cos-fake-'.$transaction->reference;
        $account = SaloonWaveClient::accountFor($transaction->type);

        $this->sessions[] = [
            'reference' => $transaction->reference,
            'amount' => $transaction->amount,
            'session_id' => $sessionId,
            'account' => $account->value,
        ];

        Log::info('Wave (local) : session ouverte', [
            'reference' => $transaction->reference,
            'amount' => $transaction->amount,
            'account' => $account->value,
        ]);

        return new WaveCheckoutSession(
            $sessionId,
            'https://pay.wave.com/fake/'.$transaction->reference,
        );
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
        return $this->balance;
    }

    /**
     * Sessions ouvertes depuis le démarrage, pour inspection dans les tests.
     *
     * @return list<array{reference: string, amount: int, session_id: string, account: string}>
     */
    public function sessions(): array
    {
        return $this->sessions;
    }

    /**
     * Fait échouer la prochaine ouverture de session — chemin « fournisseur
     * indisponible ».
     */
    public function refuseNextSession(): void
    {
        $this->refuseNext = true;
    }

    public function setBusinessBalance(?int $balance): void
    {
        $this->balance = $balance;
    }
}
