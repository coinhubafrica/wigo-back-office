<?php

return [
    'suspended' => 'Votre compte est suspendu. Contactez At Confort Plus.',
    'not_found' => 'Ressource introuvable.',
    'unauthenticated' => 'Authentification requise.',
    'forbidden' => "Vous n'avez pas accès à cette ressource.",
    'invalid_data' => 'Les données envoyées ne sont pas valides.',
    'prize_collection_note' => "À retirer au siège ATCP · pièce d'identité",
    'server_error' => 'Une erreur est survenue. Réessayez plus tard.',

    'shop' => [
        'order_placed' => 'Votre commande a été enregistrée.',
        'insufficient_stock' => 'Stock insuffisant pour « :product » : il n\'en reste que :stock.',
        'product_unavailable' => "Une des pièces commandées n'est plus disponible.",
        'idempotency_key_required' => "L'en-tête Idempotency-Key est obligatoire et doit être un UUID.",
        'idempotency_key_reused' => 'Cette clé a déjà été utilisée pour une autre requête.',
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
