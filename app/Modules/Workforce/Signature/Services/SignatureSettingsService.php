<?php

namespace App\Modules\Workforce\Signature\Services;

use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Models\SignatureSetting;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;
use App\Modules\Workforce\Signature\Services\SignatureFormatCatalog;

/**
 * W-SIGNATURE-1 (ADR-651) — réglages Signature d'une Company (résolution + mise à jour).
 */
class SignatureSettingsService
{
    /** @return array{provider:?string,default_level:string,default_format:string,validity_days:int,reminder_days:?int,default_order:string,notify_enabled:bool,send_signed_copy_to_employee:bool} */
    public function resolve(int $companyId): array
    {
        $row = SignatureSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
        $defaults = SignatureSetting::defaults();
        $company = \App\Core\Models\Company::withoutGlobalScopes()->find($companyId);

        if (! $row) {
            // Prestataire par défaut = celui résolu par le manager (interne hors PROD, rien en PROD).
            $defaults['provider'] = SignatureProviderManager::adapterFor(null)?->key();
            $defaults['default_format'] = $company ? SignatureFormatCatalog::defaultMode($company) : SignatureFormatCatalog::DEMONSTRATION;

            return $defaults;
        }

        // Mode retenu : celui stocké s'il est RÉELLEMENT disponible, sinon le mode par défaut applicable.
        $format = $row->default_format;
        if (! $format || ! $company || ! SignatureFormatCatalog::isAvailable($company, $format)) {
            $format = $company ? SignatureFormatCatalog::defaultMode($company) : SignatureFormatCatalog::DEMONSTRATION;
        }

        return [
            'provider' => $row->provider ?: SignatureProviderManager::adapterFor(null)?->key(),
            'default_level' => $row->default_level,
            'default_format' => $format,
            'validity_days' => (int) $row->validity_days,
            'reminder_days' => $row->reminder_days,
            'default_order' => $row->default_order,
            'notify_enabled' => (bool) $row->notify_enabled,
            'send_signed_copy_to_employee' => (bool) $row->send_signed_copy_to_employee,
        ];
    }

    public function update(int $companyId, array $data): array
    {
        $allowed = [
            'provider', 'default_level', 'default_format', 'validity_days', 'reminder_days',
            'default_order', 'notify_enabled', 'send_signed_copy_to_employee',
        ];
        $clean = array_intersect_key($data, array_flip($allowed));

        // Validation défensive.
        if (isset($clean['default_level']) && ! in_array($clean['default_level'], SignatureRequest::LEVELS, true)) {
            unset($clean['default_level']);
        }
        // Mode de signature : refusé s'il n'est pas RÉELLEMENT disponible pour cette Company (capacités
        // + autorisation Platform). La Company ne peut pas contourner les capacités autorisées.
        if (isset($clean['default_format'])) {
            $company = \App\Core\Models\Company::withoutGlobalScopes()->find($companyId);
            if (! in_array($clean['default_format'], SignatureFormatCatalog::MODES, true)
                || ! $company || ! SignatureFormatCatalog::isAvailable($company, $clean['default_format'])) {
                unset($clean['default_format']);
            }
        }
        if (isset($clean['default_order']) && ! in_array($clean['default_order'], SignatureRequest::ORDERS, true)) {
            unset($clean['default_order']);
        }
        if (isset($clean['validity_days'])) {
            $clean['validity_days'] = max(1, min(365, (int) $clean['validity_days']));
        }

        SignatureSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            array_merge(['company_id' => $companyId], $clean),
        );

        return $this->resolve($companyId);
    }
}
