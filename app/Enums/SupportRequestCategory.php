<?php

namespace App\Enums;

/**
 * Motif d'un ticket. Porteuse : la priorité et les deux délais SLA en
 * découlent (cf. `SupportSettings::$sla`), l'agent ne les saisit pas.
 */
enum SupportRequestCategory: string
{
    case Account = 'account';
    case Payment = 'payment';
    case Shop = 'shop';
    case Cnps = 'cnps';
    case Vehicle = 'vehicle';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Compte',
            self::Payment => 'Paiement',
            self::Shop => 'Boutique',
            self::Cnps => 'CNPS',
            self::Vehicle => 'Véhicule',
            self::Other => 'Autre',
        };
    }

    /**
     * Exemples de ce qui relève de la catégorie, affichés sous son libellé
     * dans le choix de tri : le mot seul ne dit pas où ranger « ma prime
     * n'est pas tombée », et un ticket mal rangé hérite du mauvais barème SLA.
     */
    public function description(): string
    {
        return match ($this) {
            self::Account => 'Connexion, OTP, profil, suspension',
            self::Payment => 'Solde, prime, virement, retrait',
            self::Shop => 'Commande, livraison, produit',
            self::Cnps => 'Affiliation, cotisation, attestation',
            self::Vehicle => 'Documents, assurance, changement de véhicule',
            self::Other => "Tout ce qui n'entre dans aucune des familles ci-dessus",
        };
    }
}
