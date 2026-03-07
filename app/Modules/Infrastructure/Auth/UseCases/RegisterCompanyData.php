<?php

namespace App\Modules\Infrastructure\Auth\UseCases;

final class RegisterCompanyData
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $password,
        public readonly string $companyName,
        public readonly string $jobdomainKey,
        public readonly ?string $planKey = null,
        public readonly ?string $marketKey = null,
        public readonly ?string $billingInterval = 'monthly',
    ) {}

    public static function fromValidated(array $data): self
    {
        return new self(
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            email: $data['email'],
            password: $data['password'],
            companyName: $data['company_name'],
            jobdomainKey: $data['jobdomain_key'],
            planKey: $data['plan_key'] ?? null,
            marketKey: $data['market_key'] ?? null,
            billingInterval: $data['billing_interval'] ?? 'monthly',
        );
    }
}
