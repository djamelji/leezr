<?php

namespace App\Core\Events;

use App\Core\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ModuleEnabled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Company $company,
        public string $moduleKey,
        // ADR-808 : true when the module had NEVER been active for this company (no company_modules row before).
        // Only then are the rights roles already held put on hold (a re-activation restores previous rights).
        public bool $firstActivation = false,
    ) {}
}
