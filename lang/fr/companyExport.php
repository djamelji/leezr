<?php

// ADR-845 (Leezr 4.2 M8) — export complet des données d'une société.
return [
    'owner_only' => "Seul le propriétaire de l'entreprise peut demander ou télécharger l'export complet des données.",
    'password_invalid' => 'Mot de passe incorrect.',
    'already_running' => 'Un export est déjà en cours de préparation pour cette entreprise.',
    'cooldown' => 'Un export vient d’être demandé : vous pourrez en demander un nouveau dans :minutes minutes.',
    'expired' => 'Ce lien de téléchargement a expiré ou a été révoqué : demandez un nouvel export.',
    'no_scheduled_cancellation' => 'Aucune résiliation programmée à annuler.',
    'columns' => [
        'last_name' => 'Nom', 'first_name' => 'Prénom', 'email' => 'E-mail', 'membership' => 'Rattachement', 'role' => 'Rôle', 'access' => 'Accès',
        'joined_at' => 'Depuis le', 'employee_number' => 'Matricule', 'phone' => 'Téléphone', 'status' => 'Statut', 'hire_date' => "Date d'entrée",
        'termination_date' => 'Date de sortie', 'department' => 'Service', 'job_role' => 'Poste', 'contract_type' => 'Type de contrat',
        'start_date' => 'Début', 'end_date' => 'Fin', 'weekly_hours' => 'Heures hebdomadaires', 'is_current' => 'En cours', 'reference' => 'Référence',
        'origin_address' => "Adresse d'origine", 'destination_address' => 'Adresse de destination', 'scheduled_at' => 'Prévue le', 'assigned_to' => 'Chauffeur',
        'notes' => 'Notes', 'created_at' => 'Créé le', 'number' => 'Numéro', 'issued_at' => 'Émise le', 'due_at' => 'Échéance', 'paid_at' => 'Payée le',
        'period_start' => 'Début de période', 'period_end' => 'Fin de période', 'subtotal' => 'Montant HT', 'tax_amount' => 'TVA', 'amount' => 'Montant TTC',
        'amount_due' => 'Reste dû', 'currency' => 'Devise', 'plan' => 'Offre', 'interval' => 'Périodicité', 'contracted_price' => 'Prix contracté',
        'cancel_at_period_end' => 'Résiliation programmée', 'date' => 'Date', 'action' => 'Action', 'actor_type' => "Type d'auteur", 'actor_id' => 'Auteur (id)',
        'target_type' => 'Objet', 'target_id' => 'Objet (id)', 'severity' => 'Gravité', 'kind' => 'Nature', 'id' => 'Identifiant', 'file' => 'Fichier dans l’archive',
    ],
    'files' => [
        'company' => "Identité, adresse, offre et modules actifs de l'entreprise",
        'members' => 'Utilisateurs rattachés et leur accès', 'employees' => 'Collaborateurs (fiche RH sans rémunération ni identifiant national)',
        'contracts' => 'Contrats de travail (métadonnées ; les documents signés sont dans documents/)', 'shipments' => 'Expéditions et livraisons',
        'invoices' => 'Factures émises par Leezr', 'subscriptions' => 'Historique des abonnements', 'audit' => "Journal d'audit de l'entreprise",
        'documents' => 'Index des documents (inclus, manquants ou hors budget de taille)',
        'time_entries' => 'Pointages', 'time_breaks' => 'Pauses', 'time_daily' => 'Prévu / réalisé (feuilles de temps)', 'time_planning' => 'Planning', 'time_leaves' => 'Absences et congés',
    ],
    'mail' => [
        'subject' => 'Export des données de :company prêt', 'greeting' => 'Bonjour,',
        'ready' => "L'export complet des données de :company est prêt.",
        'expires' => 'Il peut être téléchargé depuis Leezr jusqu’au :date ; il sera ensuite supprimé.',
        'action' => 'Ouvrir la page de téléchargement', 'notYou' => "Si vous n'avez pas demandé cet export, prévenez immédiatement votre administrateur.",
    ],
];
