<?php

namespace App\Modules\Infrastructure\Auth\UseCases;

use App\Core\Billing\CheckoutResult;
use App\Core\Models\Company;
use App\Core\Models\User;

final class RegisterCompanyResult
{
    public function __construct(
        public readonly User $user,
        public readonly Company $company,
        public readonly ?CheckoutResult $checkout = null,
    ) {}
}
