<?php

return [
    'accepted' => 'Le champ :attribute doit être accepté.',
    'array' => 'Le champ :attribute doit être un tableau.',
    'before_or_equal' => 'Le champ :attribute doit être une date antérieure ou égale au :date.',
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'date' => "Le champ :attribute n'est pas une date valide.",
    'date_format' => 'Le champ :attribute ne correspond pas au format :format.',
    'digits' => 'Le champ :attribute doit contenir :digits chiffres.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'exists' => 'Le champ :attribute sélectionné est invalide.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'filled' => 'Le champ :attribute doit avoir une valeur.',
    'image' => 'Le champ :attribute doit être une image.',
    'in' => 'Le champ :attribute sélectionné est invalide.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'max' => [
        'array' => 'Le champ :attribute ne doit pas contenir plus de :max éléments.',
        'file' => 'Le champ :attribute ne doit pas dépasser :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne doit pas être supérieur à :max.',
        'string' => 'Le champ :attribute ne doit pas contenir plus de :max caractères.',
    ],
    'mimes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file' => 'Le champ :attribute doit faire au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other est :value.',
    'required_with' => 'Le champ :attribute est obligatoire quand :values est présent.',
    'required_without' => "Le champ :attribute est obligatoire quand :values n'est pas présent.",
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'unique' => 'La valeur du champ :attribute est déjà utilisée.',
    'uploaded' => "Le fichier n'a pas pu être envoyé.",
    'url' => 'Le champ :attribute doit être une URL valide.',
    'uuid' => 'Le champ :attribute doit être un UUID valide.',
    'ulid' => 'Le champ :attribute doit être un ULID valide.',

    'custom' => [
        'phone' => [
            'regex' => 'Le numéro de téléphone doit être au format international, par exemple +2250717738299.',
        ],
    ],

    'attributes' => [
        'channel' => 'canal',
        'code' => 'code',
        'device_name' => "nom de l'appareil",
        'fcm_token' => 'jeton de notification',
        'phone' => 'numéro de téléphone',
        'terms_version' => 'version des conditions générales',
    ],
];
