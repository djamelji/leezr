<?php

/*
 * ADR-849 (Leezr 4.2 M10) — public registration funnel.
 * A journey is ABANDONED when it is not completed AND has had no event for `abandon_after_hours` (inactivity window).
 * Before that it is « in progress » : never counted as an abandonment. The same definition serves the Platform
 * dashboard and the abandonment alert (registration:detect-abandoned).
 */
return [
    'abandon_after_hours' => (int) env('REGISTRATION_FUNNEL_ABANDON_AFTER_HOURS', 24),
];
