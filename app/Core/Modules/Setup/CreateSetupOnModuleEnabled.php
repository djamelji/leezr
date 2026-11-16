<?php

namespace App\Core\Modules\Setup;

use App\Core\Events\ModuleEnabled;

/**
 * ADR-803 — a NEW activation of a module that declares a setup contract starts in « to configure ».
 * State only : no right is granted, nothing is applied. An existing row (re-activation) is kept as is, so the
 * previous choices and progress survive a deactivation / reactivation.
 */
class CreateSetupOnModuleEnabled
{
    public function handle(ModuleEnabled $event): void
    {
        if (! ModuleSetupService::contract($event->moduleKey)) {
            return;
        }
        CompanyModuleSetup::query()->firstOrCreate(
            ['company_id' => $event->company->id, 'module_key' => $event->moduleKey],
            ['status' => CompanyModuleSetup::TO_CONFIGURE, 'completed_steps' => []],
        );
    }
}
