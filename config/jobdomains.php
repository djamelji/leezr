<?php

/*
 * ADR-797 (L7) — JobDomain modernisation.
 *  - legacy_editing : the historical per-JobDomain configuration (roles, fields, documents, planning and
 *    their market overrides) is READ-ONLY. Technical escape hatch only, off by default ; every legacy
 *    reader (company creation, reconciliation, field/document resolution) keeps working unchanged.
 *  - versioned_init (ADR-820) : THE creation path — the published versioned presets (catalog, RBAC,
 *    organisation…) of the domain initialise a new company. A domain without a published catalog / RBAC
 *    preset (owner role) falls back to JobdomainGate::assignToCompany. Rollback : JOBDOMAIN_VERSIONED_INIT=false.
 *    Existing companies are never re-initialised.
 */
return [
    'legacy_editing' => (bool) env('JOBDOMAIN_LEGACY_EDITING', false),
    'versioned_init' => [
        'enabled' => (bool) env('JOBDOMAIN_VERSIONED_INIT', true), // ADR-820 : chemin effectif ; false = repli historique
        // ADR-808 — controlled switch : NEW companies of these jobdomains only (comma list). Empty = none, even
        // when enabled. Rollback = remove the key (or disable) : future creations go back to the historical path.
        'jobdomains' => array_values(array_filter(array_map('trim', explode(',', (string) env('JOBDOMAIN_VERSIONED_INIT_JOBDOMAINS', '*'))))),
    ],
];
