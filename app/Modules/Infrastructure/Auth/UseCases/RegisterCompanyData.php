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
        public readonly ?string $legalStatusKey = null,
        public readonly array $dynamicFields = [],
        public readonly array $addonKeys = [],
        public readonly bool $billingSameAsCompany = true,
        public readonly ?string $couponCode = null,
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
            legalStatusKey: $data['legal_status_key'] ?? null,
            dynamicFields: $data['dynamic_fields'] ?? [],
            addonKeys: $data['addon_keys'] ?? [],
            billingSameAsCompany: $data['billing_same_as_company'] ?? true,
            couponCode: $data['coupon_code'] ?? null,
        );
    }
}
