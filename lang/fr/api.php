<?php

return [
    'suspended' => 'Votre compte est suspendu. Contactez At Confort Plus.',
    'not_found' => 'Ressource introuvable.',
    'unauthenticated' => 'Authentification requise.',
    'forbidden' => "Vous n'avez pas accès à cette ressource.",
    'invalid_data' => 'Les données envoyées ne sont pas valides.',
    'prize_collection_note' => "À retirer au siège ATCP · pièce d'identité",
    'server_error' => 'Une erreur est survenue. Réessayez plus tard.',

    'driver' => [
        'photo_updated' => 'Votre photo de profil a été mise à jour.',
        'photo_too_small' => 'La photo doit faire au moins 200 pixels de côté.',
        'photo_missing' => "Aucune photo n'est associée à ce conducteur.",
    ],

    'shop' => [
        'order_placed' => 'Votre commande a été enregistrée.',
        'insufficient_stock' => 'Stock insuffisant pour « :product » : il n\'en reste que :stock.',
        'product_unavailable' => "Une des pièces commandées n'est plus disponible.",
        'no_pickup_point' => "Aucune agence de retrait n'est disponible pour le moment.",
    ],

    /*
    | Idempotence : hors du bloc `shop`, le middleware sert aussi les recharges.
    */
    'idempotency' => [
        'key_required' => "L'en-tête Idempotency-Key est obligatoire et doit être un UUID.",
        'key_reused' => 'Cette clé a déjà été utilisée pour une autre requête.',
    ],

    'recharge' => [
        'initiated' => 'Votre recharge a été initiée.',
        'amount_below_min' => 'Le montant minimum est de :min FCFA.',
        'amount_above_max' => 'Le montant maximum est de :max FCFA.',
        'daily_cap_reached' => 'Vous avez atteint le plafond de :cap FCFA par jour.',
        'provider_unavailable' => 'Le paiement Wave est momentanément indisponible. Réessayez dans un instant.',
        'invalid_signature' => 'Signature invalide.',
    ],

    'cnps' => [
        'period_format' => 'Le mois doit être au format AAAA-MM.',
        'period_future' => 'Vous ne pouvez pas déclarer un mois à venir.',
        'period_too_old' => 'Vous ne pouvez déclarer que les vingt-quatre derniers mois.',
        'reference_bounds' => 'Le montant de référence doit être compris entre 3 600 et 21 600 FCFA.',
        'declaration_recorded' => 'Votre déclaration a été enregistrée.',
        'reference_updated' => 'Votre montant mensuel de référence a été mis à jour.',
        'proof_missing' => 'Aucun justificatif n\'est attaché à cette déclaration.',
    ],
];
