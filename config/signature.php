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

    /*
    |--------------------------------------------------------------------------
    | Prestataire de signature EXTERNE (ADR-765, DMS M6)
    |--------------------------------------------------------------------------
    | Branchement d'un prestataire réel (Yousign/DocuSign/etc.) via le contrat d'adapter horizontal.
    | SÉCURITÉ : `webhook_secret` obligatoire pour une utilisation active (sinon DEV/sandbox seulement,
    | bloqué en PROD). `certified` ne doit être activé QUE pour un prestataire réellement certifié avec
    | preuves démontrables — il conditionne l'annonce des niveaux avancé/qualifié. Aucune donnée en dur.
    */
    'external' => [
        'enabled' => (bool) env('SIGNATURE_EXTERNAL_ENABLED', false),
        'api_base' => env('SIGNATURE_EXTERNAL_API_BASE', ''),
        'api_key' => env('SIGNATURE_EXTERNAL_API_KEY', ''),
        'webhook_secret' => env('SIGNATURE_EXTERNAL_WEBHOOK_SECRET', ''),
        'certified' => (bool) env('SIGNATURE_EXTERNAL_CERTIFIED', false),
        'levels' => array_filter(explode(',', (string) env('SIGNATURE_EXTERNAL_LEVELS', 'simple'))),
        'replay_tolerance_seconds' => (int) env('SIGNATURE_EXTERNAL_REPLAY_TOLERANCE', 300),
    ],

    // Chemin absolu optionnel du binaire `pdfsig` (poppler). Si vide, résolution via PATH puis
    // emplacements connus — utile quand le PATH de php-fpm/Valet n'inclut pas Homebrew.
    'pdfsig_path' => env('SIGNATURE_PDFSIG_PATH', ''),

    /*
    |--------------------------------------------------------------------------
    | Politique Platform par Market (W-SIGNATURE-10)
    |--------------------------------------------------------------------------
    | Pilote les capacités autorisées + l'assurance d'AUTHENTIFICATION minimale exigée pour signer, par
    | Market. Le défaut (sans entrée) : interne → account_authenticated, externe → declared. Une entrée ne
    | peut que RENFORCER. Un réglage n'attribue JAMAIS un niveau eIDAS avancé/qualifié.
    |
    | Valeurs d'assurance : declared < link_only < account_authenticated < account_mfa.
    | Exemple (non actif par défaut) :
    |   'markets' => ['FR' => [
    |       'formats' => ['personal_digital', 'demonstration'],
    |       'min_assurance' => ['internal' => 'account_authenticated', 'external' => 'declared'],
    |   ]],
    */
    'platform' => [
        'markets' => [],
    ],
];
