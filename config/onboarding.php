<?php

/*
 * ADR-796.1 — onboarding files (L6). Retention durations remain per file (HR-declared) and must be
 * validated legally per market before real use. `retention.auto_apply` = false : the scheduled run is a
 * DRY RUN (report only) ; a real purge needs an explicit decision.
 */
return [
    'retention' => [
        'auto_apply' => (bool) env('ONBOARDING_RETENTION_AUTO_APPLY', false),
    ],
    'mail' => [
        'cooldown_minutes' => 2,
        'max_per_day' => 5,
    ],
    'code' => [
        'max_sends_per_day' => 5,
        'max_failures' => 10,
    ],
];
