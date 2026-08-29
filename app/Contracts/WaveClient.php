<?php

namespace App\Contracts;

use App\Models\Transaction;
use App\Services\Wave\WaveCheckoutSession;

/**
 * Encaissement Mobile Money par Wave Checkout.
 */
interface WaveClient
{
    /**
     * Ouvre une session de paiement. La `reference` de la transaction part en
     * `client_reference` : c'est par elle que le webhook retrouvera la ligne.
     *
     * Rend `null` si le fournisseur refuse — la recharge ne peut pas commencer.
     */
    public function createCheckoutSession(Transaction $transaction): ?WaveCheckoutSession;

    /**
     * Vérifie la signature HMAC-SHA256 du corps brut du webhook.
     */
    public function verifySignature(string $payload, ?string $signature): bool;

    /**
     * Solde du compte Wave Business, pour la réconciliation en back-office.
     * Nul si le fournisseur ne répond pas — l'écran affiche alors « — ».
     */
    public function businessBalance(): ?int;
}
