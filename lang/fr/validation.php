<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines — French
    |--------------------------------------------------------------------------
    |
    | Only the messages needed by the registration flow.
    | Laravel's Password rule generates its own messages via these keys.
    |
    */

    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'max' => [
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],
    'min' => [
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',

    'password' => [
        'letters' => 'Le :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le :attribute doit contenir au moins un caractère spécial.',
        'uncompromised' => 'Ce :attribute a été compromis dans une fuite de données. Veuillez en choisir un autre.',
    ],
    'unique' => 'Cette valeur est déjà utilisée.',
    'exists' => 'La valeur sélectionnée est invalide.',
    'in' => 'La valeur sélectionnée est invalide.',
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'array' => 'Le champ :attribute doit être un tableau.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'nullable' => 'Le champ :attribute peut être nul.',
    'date' => 'Le champ :attribute doit être une date valide.',

    'attributes' => [
        'first_name' => 'prénom',
        'last_name' => 'nom',
        'email' => 'e-mail',
        'password' => 'mot de passe',
        'company_name' => 'nom de l\'entreprise',
        'jobdomain_key' => 'secteur d\'activité',
        'plan_key' => 'plan',
        'market_key' => 'marché',
        'billing_interval' => 'fréquence de facturation',
        'coupon_code' => 'code promo',
    ],
];
