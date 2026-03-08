<?php

namespace App\Modules\Platform\Companies\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Core\Fields\FieldValidationService;
use App\Core\Fields\FieldWriteService;
use App\Core\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyProfileAdminController
{
    public function update(Request $request, int $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        $fixedRules = ['name' => ['required', 'string', 'max:255']];
        $dynamicRules = FieldValidationService::rules(
            FieldDefinition::SCOPE_COMPANY, $company->id, marketKey: $company->market_key,
        );

        $validated = $request->validate(array_merge($fixedRules, $dynamicRules));

        $before = $company->only('name');
        $company->update(array_intersect_key($validated, array_flip(['name'])));

        if (isset($validated['dynamic_fields'])) {
            FieldWriteService::upsert(
                $company,
                $validated['dynamic_fields'],
                FieldDefinition::SCOPE_COMPANY,
                $company->id,
                $company->market_key,
            );
        }

        app(AuditLogger::class)->logPlatform(
            AuditAction::COMPANY_SETTINGS_UPDATED, 'company', (string) $company->id,
            ['diffBefore' => $before, 'diffAfter' => $company->only('name')],
        );

        $company->refresh();

        return response()->json([
            'message' => 'Company profile updated.',
            'company' => $company,
            'dynamic_fields' => FieldResolverService::resolve(
                model: $company,
                scope: FieldDefinition::SCOPE_COMPANY,
                companyId: $company->id,
                marketKey: $company->market_key,
                locale: FieldResolverService::requestLocale(),
            ),
        ]);
    }
}
