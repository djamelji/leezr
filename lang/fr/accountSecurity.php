<?php

// ADR-838 (Leezr 4.2 M2) — opérations sensibles sur le compte.
return [
    'password_invalid' => 'Mot de passe incorrect.',
    'code_required' => 'Saisissez le code de votre application d\'authentification ou un code de secours.',
    'code_invalid' => 'Code invalide ou expiré. Saisissez le code affiché actuellement par votre application, ou un code de secours non utilisé.',
    'not_enabled' => 'La double authentification n\'est pas activée sur ce compte.',
    'too_many_attempts' => 'Trop de tentatives. Réessayez dans :minutes minute(s).',
    'disabled' => 'La double authentification a été désactivée.',
    'codes_regenerated' => 'Nouveaux codes de secours générés. Les anciens ne fonctionnent plus.',
    'mail' => [
        'subject' => 'Sécurité de votre compte Leezr',
        'greeting' => 'Bonjour,',
        'two_factor_disabled' => 'La double authentification vient d\'être désactivée sur votre compte.',
        'backup_codes_regenerated' => 'De nouveaux codes de secours viennent d\'être générés pour votre compte. Les anciens codes ne fonctionnent plus.',
        'password_set_by_admin' => 'Le mot de passe de votre compte vient d\'être modifié par un administrateur de :space. Vos sessions ouvertes ont été fermées.',
        'password_changed' => 'Le mot de passe de votre compte vient d\'être modifié. Vos autres sessions ouvertes ont été fermées.',
        'not_you' => 'Si vous n\'êtes pas à l\'origine de cette opération, réinitialisez votre mot de passe sans attendre et prévenez votre administrateur.',
        'salutation' => 'L\'équipe Leezr',
    ],
];
