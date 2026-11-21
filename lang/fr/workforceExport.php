<?php

// ADR-844 (Leezr 4.2 M7) — exports temps / planning / absences : en-têtes du fichier CSV et messages.
return [
    'too_large' => "Plus de :max lignes : réduisez la période ou le nombre de collaborateurs.",
    'period_too_long' => 'La période ne peut pas dépasser :days jours.',
    'module_inactive' => "Ce type de données n'est pas disponible pour votre entreprise.",
    'values' => [
        'bool' => ['yes' => 'oui', 'no' => 'non'],
        'entry' => ['idle' => 'non commencé', 'working' => 'en cours', 'on_break' => 'en pause', 'completed' => 'terminé'],
        'timesheet' => ['draft' => 'brouillon', 'submitted' => 'soumise', 'approved' => 'approuvée', 'rejected' => 'refusée', 'locked' => 'verrouillée'],
        'shift' => ['draft' => 'brouillon', 'published' => 'publié', 'completed' => 'réalisé', 'cancelled' => 'annulé'],
        'leave' => ['pending' => 'en attente', 'approved' => 'approuvée', 'consumed' => 'prise', 'rejected' => 'refusée', 'cancelled' => 'annulée'],
        'break' => ['lunch' => 'repas', 'rest' => 'repos', 'personal' => 'personnelle'],
    ],
    'columns' => [
        'date' => 'Date', 'employee_number' => 'Matricule', 'last_name' => 'Nom', 'first_name' => 'Prénom', 'department' => 'Service',
        'clock_in' => 'Arrivée', 'clock_out' => 'Départ', 'break_minutes' => 'Pauses (min)', 'worked_minutes' => 'Travaillé (min)',
        'worked_hhmm' => 'Travaillé (hh:mm)', 'status' => 'Statut', 'source' => 'Origine', 'start' => 'Début', 'end' => 'Fin',
        'type' => 'Type', 'duration_minutes' => 'Durée (min)', 'planned_minutes' => 'Prévu (min)',
        'difference_minutes' => 'Écart réalisé − prévu (min)', 'overtime_identified_minutes' => 'Heures sup. identifiées (min)',
        'leave_minutes' => 'Absence (min)', 'leave_type' => "Type d'absence", 'rest_day' => 'Jour de repos', 'anomalies' => 'Anomalies',
        'timesheet_status' => 'Statut de la feuille de temps', 'validated' => 'Validé', 'site' => 'Site', 'timezone' => 'Fuseau horaire',
        'shift_minutes' => 'Durée du créneau (min)', 'from' => 'Du', 'to' => 'Au', 'start_time' => 'Heure de début', 'end_time' => 'Heure de fin',
        'days' => 'Jours', 'hours' => 'Heures', 'reviewed_at' => 'Décision le',
    ],
];
