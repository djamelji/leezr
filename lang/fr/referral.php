<?php

// ADR-819 (Company 2.0) — Workforce Parrainage.
return [
    'employee_required' => "Le parrainage est réservé aux salariés de l'entreprise.",
    'channel_disabled' => "Ce mode de parrainage n'est pas proposé par votre entreprise.",
    'position_not_open' => "Ce poste n'est pas ouvert au parrainage.",
    'daily_limit' => 'Vous avez atteint la limite de :n parrainages par jour. Réessayez demain.',
    'not_withdrawable' => 'Ce parrainage ne peut plus être retiré : la candidature a été transmise.',
    'already_decided' => 'Une décision a déjà été prise pour cette candidature.',
    'info_message_required' => 'Précisez ce que le candidat doit compléter.',
    'convert_requires_acceptance' => "Retenez d'abord la candidature avant d'ouvrir un dossier d'embauche.",
    'nothing_to_collect' => "Aucune information ni pièce n'est prévue pour ce poste : configurez le poste avant d'ouvrir le dossier.",
    'link_invalid' => "Ce lien n'est pas valide ou a expiré. Demandez un nouveau lien à la personne qui vous l'a transmis.",
    'notice_text' => ':company utilise vos informations pour préparer votre embauche. Elles sont visibles uniquement des personnes chargées du recrutement.',
    'consent_text' => "J'accepte que :company utilise ces informations pour étudier ma candidature. La personne qui m'a recommandé(e) ne voit pas ma candidature. Mes informations sont supprimées si je ne suis pas retenu(e), à l'issue de la durée de conservation de l'entreprise.",
    'mail' => [
        'subject' => ':company : une personne de votre entourage vous recommande',
        'greeting' => 'Bonjour,',
        'line' => ':referrer pense que le poste « :position » chez :company pourrait vous intéresser.',
        'action' => 'Découvrir le poste et candidater',
        'expiry' => 'Ce lien est personnel et valable jusqu’au :date.',
        'optional' => "Si cela ne vous intéresse pas, ignorez simplement ce message : aucune information ne sera transmise à l'entreprise.",
        'info_subject' => ':company : un complément pour votre candidature',
        'info_line' => ':company a besoin d’un complément d’information pour étudier votre candidature.',
        'info_action' => 'Compléter ma candidature',
    ],
];
