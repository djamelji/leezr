<?php

/*
 * ADR-797 (L7) — JobDomain modernisation.
 *  - legacy_editing : the historical per-JobDomain configuration (roles, fields, documents, planning and
 *    their market overrides) is READ-ONLY. Technical escape hatch only, off by default ; every legacy
 *    reader (company creation, reconciliation, field/document resolution) keeps working unchanged.
 *  - versioned_init : the versioned company initialisation is PREPARED but NOT operational ; the
 *    historical JobdomainGate::assignToCompany stays the only creation path until parity is proven.
 */
return [
    'legacy_editing' => (bool) env('JOBDOMAIN_LEGACY_EDITING', false),
    'versioned_init' => [
        'enabled' => (bool) env('JOBDOMAIN_VERSIONED_INIT', false),
    ],
];
