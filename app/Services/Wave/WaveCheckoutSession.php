<?php

namespace App\Services\Wave;

/**
 * Session de paiement ouverte chez Wave. `launchUrl` est l'adresse que
 * l'application mobile ouvre pour que le conducteur paie.
 */
readonly class WaveCheckoutSession
{
    public function __construct(
        public string $id,
        public string $launchUrl,
    ) {}
}
