<?php

namespace App\Core\Events;

use App\Core\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ModuleDisabled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Company $company,
        public string $moduleKey,
    ) {}
}
