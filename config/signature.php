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
];
