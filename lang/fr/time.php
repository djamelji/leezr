<?php

// ADR-818 (chantier F) — messages du temps de travail (pointages, corrections).
return [
    'open_entry_needs_clock_out' => 'Ce pointage est encore ouvert : indiquez l\'heure de sortie pour le clôturer.',
    'entry_not_correctable' => 'Ce pointage ne peut pas être corrigé dans son état actuel.',
    'clock_out_in_future' => 'L\'heure de sortie ne peut pas être dans le futur.',
    'clock_in_before_out' => 'L\'heure d\'arrivée doit précéder l\'heure de sortie.',
    'locked_timesheet' => 'Ce pointage appartient à une feuille de temps validée ou verrouillée : il ne peut plus être modifié.',
    'already_completed' => 'Ce pointage est déjà terminé.',
    'already_clocked_in' => 'Un pointage est déjà en cours : pointez votre sortie d\'abord.',
];
