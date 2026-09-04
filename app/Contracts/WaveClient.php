<?php

namespace App\Contracts;

use App\Models\Transaction;
use App\Services\Wave\WaveCheckoutSession;
use App\Settings\WaveAccount;

/**
 * Encaissement Mobile Money par Wave Checkout.
 *
 * Le back-office opère deux comptes Wave (boutique et recharge) : chaque appel
 * dit lequel il vise. Pour une session de paiement, le compte se déduit du type
 * de transaction ; pour le solde et la signature, il est passé explicitement,
 * l'appelant sachant quel flux il regarde.
 */
interface WaveClient
{
    /**
     * Ouvre une session de paiement sur le compte que désigne le type de la
     * transaction. La `reference` part en `client_reference` : c'est par elle
     * que le webhook retrouvera la ligne.
     *
     * Rend `null` si le fournisseur refuse — la recharge ne peut pas commencer.
     */
    public function createCheckoutSession(Transaction $transaction): ?WaveCheckoutSession;

    /**
     * Vérifie la signature HMAC-SHA256 du corps brut du webhook, avec le secret
     * du compte visé.
     *
     * Le compte vient de l'URL de rappel, pas du corps : un payload ne peut pas
     * désigner lui-même la clé qui l'authentifie.
     */
    public function verifySignature(WaveAccount $account, string $payload, ?string $signature): bool;

    /**
     * Solde du compte Wave Business, pour la réconciliation en back-office.
     * Nul si le fournisseur ne répond pas — l'écran affiche alors « — ».
     */
    public function businessBalance(WaveAccount $account): ?int;
}
