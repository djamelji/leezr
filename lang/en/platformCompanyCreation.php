<?php

// ADR-834 — server messages of the Platform company creation wizard.
return [
    'errors' => [
        'moduleRefused' => 'The module :module could not be activated: :reason',
        'moduleIncompatible' => 'The module :module is not available for this activity.',
        'modulePlanRequired' => 'The module :module requires the :plan plan.',
        'moduleUnavailable' => 'The module :module is not available for sale.',
        'dependencyUnavailable' => 'The module :module needs :dependency, which is not available with this plan.',
        'paidOptionsNeedTrial' => 'Paid options can only be activated at creation during a trial (they are billed after the trial). Activate them later from the company record.',
        'overrideForbidden' => 'You are not allowed to grant commercial terms different from the plan.',
        'overrideFreePlan' => 'The free plan has no commercial terms to adjust.',
        'jobdomainInactive' => 'This activity is not available.',
        'legalStatusMarket' => 'This legal form does not exist in the selected country.',
        'unknownField' => 'This field is not part of the company information for this activity and country.',
        'fieldRequired' => ':field is required.',
        'fieldTooLong' => ':field is too long.',
        'fieldFormat' => ':field does not have the expected format.',
    ],
];
