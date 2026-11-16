<?php

namespace App\Core\Modules\Setup;

use App\Core\Events\ModuleEnabled;

/**
 * ADR-803 — a NEW activation of a module that declares a setup contract starts in « to configure ».
 * State only : no right is granted, nothing is applied. On re-activation the row (choices, progress) is kept
 * and a previous « ready » goes back to « in progress » until confirmed again (ADR-803.1).
 */
class CreateSetupOnModuleEnabled
{
    public function handle(ModuleEnabled $event): void
    {
        if (! ModuleSetupService::contract($event->moduleKey)) {
            return;
        }
        $row = CompanyModuleSetup::query()->firstOrCreate(
            ['company_id' => $event->company->id, 'module_key' => $event->moduleKey],
            ['status' => CompanyModuleSetup::TO_CONFIGURE, 'completed_steps' => []],
        );
        // ADR-808 — FIRST activation : rights roles already held for this module (dormant, e.g. granted through a
        // composite capability or an old role editor) are put on hold until the access step is confirmed.
        if ($row->wasRecentlyCreated && $event->firstActivation && ($held = PendingModuleAccess::snapshot($event->company, $event->moduleKey))) {
            $row->forceFill(['pending_access' => $held])->save();
            app(\App\Core\Audit\AuditLogger::class)->logCompany($event->company->id, 'module.access_pending', 'module', $event->moduleKey,
                ['metadata' => ['roles' => array_keys($held)]]);
        }
        // ADR-803.1 — re-activation : choices kept, but a previous « ready » must be confirmed again.
        if (! $row->wasRecentlyCreated) {
            (new ModuleSetupService($event->company))->onReactivated($event->moduleKey);
        }
    }
}
