<?php

namespace App\Modules\Dashboard\Contracts;

/**
 * ADR-816 — a widget whose result aggregates the WHOLE company (everyone's presence, anomalies, hours,
 * pending leave, headcount, shifts). It is offered and resolved only to users who may see everyone
 * in that domain — `WorkforceAccess::viewAllWith()`, the same authority as the Workforce controllers.
 * A personal right (`timesheet_view` « own », `leave_request`) never grants it.
 */
interface CollectiveWidget
{
    /** 'time' | 'leave' | 'employee' | 'planning' (see WorkforceAccess::viewAllWith). */
    public function collectiveDomain(): string;
}
