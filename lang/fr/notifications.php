<?php

return [
    'billing_payment_failed' => [
        'title' => 'Paiement échoué',
        'body' => 'Une tentative de paiement pour la facture #:invoice_id a échoué.',
    ],
    'billing_invoice_created' => [
        'title' => 'Nouvelle facture',
        'body' => 'La facture #:invoice_id de :amount a été créée.',
    ],
    'billing_payment_received' => [
        'title' => 'Paiement reçu',
        'body' => 'Un paiement de :amount a été reçu.',
    ],
    'billing_plan_changed' => [
        'title' => 'Plan modifié',
        'body' => 'Votre plan a été changé pour :plan_name.',
    ],
    'billing_trial_expiring' => [
        'title' => 'Essai expirant',
        'body' => 'Votre essai expire dans :days jours.',
    ],
    'billing_trial_started' => [
        'title' => 'Essai démarré',
        'body' => 'Votre essai a commencé. Bienvenue !',
    ],
    'billing_trial_converted' => [
        'title' => 'Essai converti',
        'body' => 'Votre essai a été converti en abonnement actif.',
    ],
    'billing_payment_method_expiring' => [
        'title' => 'Moyen de paiement expirant',
        'body' => 'Votre moyen de paiement se terminant par :last4 expire bientôt.',
    ],
    'billing_account_suspended' => [
        'title' => 'Compte suspendu',
        'body' => 'Votre compte a été suspendu en raison de factures impayées.',
    ],
    'billing_addon_activated' => [
        'title' => 'Module activé',
        'body' => 'Le module :module_name a été activé.',
    ],
    'members_invited' => [
        'title' => 'Membre invité',
        'body' => ':member_name a été invité à rejoindre l\'équipe.',
    ],
    'members_joined' => [
        'title' => 'Membre rejoint',
        'body' => ':member_name a rejoint l\'équipe.',
    ],
    'members_removed' => [
        'title' => 'Membre retiré',
        'body' => ':member_name a été retiré de l\'équipe.',
    ],
    'members_role_changed' => [
        'title' => 'Rôle modifié',
        'body' => 'Le rôle de :member_name a été changé pour :role_name.',
    ],
    'modules_activated' => [
        'title' => 'Module activé',
        'body' => 'Le module :module_name a été activé.',
    ],
    'modules_deactivated' => [
        'title' => 'Module désactivé',
        'body' => 'Le module :module_name a été désactivé.',
    ],
    'documents_expiring_soon' => [
        'title' => 'Document bientôt expiré',
        'body' => 'Le document :document_type de :member_name expire le :expires_at.',
    ],
    'documents_expired' => [
        'title' => 'Document expiré',
        'body' => 'Le document :document_type de :member_name a expiré le :expires_at.',
    ],
    'documents_submitted' => [
        'title' => 'Document soumis',
        'body' => ':member_name a soumis le document :document_type pour vérification.',
    ],
    'documents_request_new' => [
        'title' => 'Document demandé',
        'body' => 'Un document :document_type vous a été demandé.',
    ],
    'documents_reviewed' => [
        'title' => 'Document vérifié',
        'body' => 'Votre document :document_type a été :status.',
    ],

    'security_alert' => [
        'title' => 'Alerte de sécurité',
        'body' => 'Un événement de sécurité a été détecté.',
    ],

    // Platform-scoped topics
    'platform_new_subscription' => [
        'title' => 'Nouvel abonnement',
        'body' => 'L\'entreprise :company_name a souscrit au plan :plan_name.',
    ],
    'platform_plan_changed' => [
        'title' => 'Changement de plan',
        'body' => 'L\'entreprise :company_name est passée du plan :old_plan au plan :new_plan.',
    ],
    'platform_cancellation_requested' => [
        'title' => 'Demande de résiliation',
        'body' => 'L\'entreprise :company_name a demandé la résiliation de son abonnement.',
    ],
    'platform_payment_failed_alert' => [
        'title' => 'Échec de paiement',
        'body' => 'Le paiement de l\'entreprise :company_name a échoué (facture #:invoice_id).',
    ],
    'platform_new_company_registered' => [
        'title' => 'Nouvelle entreprise inscrite',
        'body' => 'L\'entreprise :company_name vient de s\'inscrire sur la plateforme.',
    ],
    'platform_trial_expired' => [
        'title' => 'Essai expiré',
        'body' => 'L\'essai de l\'entreprise :company_name a expiré sans conversion.',
    ],
    'platform_account_suspended' => [
        'title' => 'Compte suspendu',
        'body' => 'Le compte de l\'entreprise :company_name a été suspendu.',
    ],

    // Support topics
    'support_ticket_created' => [
        'title' => 'Nouveau ticket support',
        'body' => ':company_name a ouvert un ticket : :subject',
    ],
    'support_ticket_replied' => [
        'title' => 'Réponse sur votre ticket',
        'body' => 'Nouvelle réponse sur le ticket « :subject ».',
    ],
    'support_ticket_resolved' => [
        'title' => 'Ticket résolu',
        'body' => 'Votre ticket « :subject » a été résolu.',
    ],
    'support_ticket_assigned' => [
        'title' => 'Ticket assigné',
        'body' => 'Le ticket « :subject » vous a été assigné.',
    ],
];
