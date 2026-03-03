<?php

namespace App\Modules\Infrastructure\Auth\UseCases;

use App\Core\Models\Company;
use App\Core\Models\User;

final class RegisterCompanyResult
{
    public function __construct(
        public readonly User $user,
        public readonly Company $company,
    ) {}
}
