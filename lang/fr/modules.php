<?php

return [
    // ADR-821 — dépendances des modules
    'dependency_not_covered' => 'Offre incohérente : « :module » est proposé dans l\'offre :plan, mais le module dont il dépend (« :dependency ») ne l\'est pas au même niveau. Incluez d\'abord la dépendance.',
    'dependency_inconsistent' => 'Les dépendances de ces modules sont incohérentes (cycle ou module inconnu).',
    'compatibility_in_use' => '« :module » est encore actif dans des sociétés de ce métier : il reste compatible.',
];
