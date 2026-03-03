<?php

namespace App\Modules\Infrastructure\Auth\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterCompanyUseCase
{
    public function execute(RegisterCompanyData $data): RegisterCompanyResult
    {
        $result = DB::transaction(function () use ($data) {
            $user = User::create([
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'email' => $data->email,
                'password' => $data->password,
                'password_set_at' => now(),
            ]);

            $planKey = $data->planKey ?? 'starter';

            // ADR-165: resolve market — explicit > default > null
            $marketKey = $data->marketKey;
            if (!$marketKey) {
                $defaultMarket = \App\Core\Markets\Market::where('is_default', true)->first();
                $marketKey = $defaultMarket?->key;
            }

            // ADR-167a: jobdomain_key is required — no fallback
            $company = Company::create([
                'name' => $data->companyName,
                'slug' => Str::slug($data->companyName) . '-' . Str::random(4),
                'plan_key' => $planKey,
                'market_key' => $marketKey,
                'jobdomain_key' => $data->jobdomainKey,
            ]);

            $company->memberships()->create([
                'user_id' => $user->id,
                'role' => 'owner',
            ]);

            // ADR-100: Assign jobdomain + activate defaults (modules, fields, roles, dashboard)
            JobdomainGate::assignToCompany($company, $data->jobdomainKey);

            return new RegisterCompanyResult($user, $company);
        });

        // Audit outside transaction — non-critical side-effect
        app(AuditLogger::class)->logCompany(
            $result->company->id,
            AuditAction::REGISTER,
            'user',
            (string) $result->user->id,
            ['actorId' => $result->user->id, 'metadata' => ['email' => $result->user->email]],
        );

        return $result;
    }
}
