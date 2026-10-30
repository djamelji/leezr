<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AC interne DEV (W-SIGNATURE-7, ADR-659)
    |--------------------------------------------------------------------------
    | Autorité de certification STRICTEMENT dédiée à DEV/test. Non reconnue, non
    | qualifiée, distincte du cachet organisationnel serveur. Désactivée en PROD.
    */
    'dev_ca' => [
        // Émission activée hors PROD par défaut ; peut être forcée via SIGNATURE_DEV_CA_ENABLED.
        'enabled' => env('SIGNATURE_DEV_CA_ENABLED', ! in_array(env('APP_ENV'), ['production', 'prod'], true)),

        // Durée de validité par défaut des certificats signataires (jours).
        'validity_days' => (int) env('SIGNATURE_DEV_CA_VALIDITY_DAYS', 365),

        // Secret de chiffrement DÉDIÉ des clés d'AC, SÉPARÉ d'APP_KEY (évite qu'une rotation d'APP_KEY
        // rende les clés d'AC illisibles). Format : "base64:<32 octets>" ou 32 octets bruts. Si vide,
        // repli sur APP_KEY (dépendance documentée, à séparer avant toute pérennité — cf. ADR-660 §6).
        'key' => env('SIGNATURE_DEV_CA_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature PAdES externe (clé navigateur)
    |--------------------------------------------------------------------------
    */
    'external_pades' => [
        // Durée de vie d'une préparation de signature (to_sign) avant péremption (secondes).
        'prep_ttl_seconds' => (int) env('SIGNATURE_PREP_TTL', 900),
    ],

    // Chemin absolu optionnel du binaire `pdfsig` (poppler). Si vide, résolution via PATH puis
    // emplacements connus — utile quand le PATH de php-fpm/Valet n'inclut pas Homebrew.
    'pdfsig_path' => env('SIGNATURE_PDFSIG_PATH', ''),
];
