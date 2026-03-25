<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\CompanyDocumentSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-397: Company document automation settings (show + update).
 */
class DocumentSettingController
{
    public function show(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $settings = CompanyDocumentSetting::forCompany($company->id);

        return response()->json(['settings' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'auto_renew_enabled' => ['required', 'boolean'],
            'renew_days_before' => ['required', 'integer', 'min:1', 'max:365'],
            'auto_remind_enabled' => ['required', 'boolean'],
            'remind_after_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        $settings = CompanyDocumentSetting::forCompany($company->id);
        $settings->update($validated);

        return response()->json([
            'message' => 'Document settings updated.',
            'settings' => $settings->fresh(),
        ]);
    }
}
