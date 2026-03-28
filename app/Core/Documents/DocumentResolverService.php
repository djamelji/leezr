<?php

namespace App\Core\Documents;

use App\Company\RBAC\CompanyRole;
use App\Core\Models\User;

/**
 * ADR-169 Phase 3 / ADR-174 / ADR-384: Resolve document types.
 * Returns document types with their upload status, requirement, lifecycle, and metadata.
 * Pattern: 3 queries (types → activations → uploads).
 *
 * DETTE-DOC-001 resolved (ADR-174): $scope parameter filters Q1.
 * When scope=company or user=null, Q3 (MemberDocument) is skipped entirely.
 * CompanyDocument hydration is handled by CompanyDocumentReadModel, not here.
 *
 * ADR-384: lifecycle_status computed via DocumentLifecycleService (pure, no mutation).
 */
class DocumentResolverService
{
    public static function resolve(
        ?User $user,
        int $companyId,
        ?string $roleKey = null,
        ?string $marketKey = null,
        ?string $scope = null,
    ): array {
        // Q1: All document types (system + company custom, excluding archived)
        $query = DocumentType::where(function ($q) use ($companyId) {
            $q->where('is_system', true)
                ->orWhere('company_id', $companyId);
        })->whereNull('archived_at');
        if ($scope !== null) {
            $query->where('scope', $scope);
        }
        $types = $query->get()->keyBy('id');

        if ($types->isEmpty()) {
            return [];
        }

        // Filter by market
        if ($marketKey) {
            $types = $types->filter(function ($type) use ($marketKey) {
                $markets = $type->validation_rules['applicable_markets'] ?? null;

                return $markets === null || in_array($marketKey, $markets);
            });
        }

        // Q2: Activations for this company
        $activations = DocumentTypeActivation::whereIn('document_type_id', $types->keys())
            ->where('company_id', $companyId)
            ->where('enabled', true)
            ->get()
            ->keyBy('document_type_id');

        if ($activations->isEmpty()) {
            return [];
        }

        // Q3: Existing uploads for this user (skipped for company scope or null user)
        if ($user !== null && $scope !== DocumentType::SCOPE_COMPANY) {
            $uploads = MemberDocument::where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->whereIn('document_type_id', $activations->keys())
                ->get()
                ->keyBy('document_type_id');
        } else {
            $uploads = collect();
        }

        // Mandatory context (cached per request)
        $mandatoryContext = DocumentMandatoryContext::load($companyId);

        // ADR-170: Load role for tag-based mandatory resolution (optional query, skipped when no user)
        $role = null;
        $roleRequiredTags = null;
        if ($user !== null && $roleKey !== null) {
            $role = CompanyRole::where('company_id', $companyId)
                ->where('key', $roleKey)
                ->first();
            $roleRequiredTags = $role?->required_tags;
        }

        $result = [];

        foreach ($activations as $typeId => $activation) {
            $type = $types->get($typeId);
            if (!$type) {
                continue;
            }

            $mandatory = DocumentMandatoryContext::isMandatory($type, $mandatoryContext, $roleRequiredTags);
            $required = $mandatory || $activation->required_override;

            $upload = $uploads->get($typeId);
            $rules = $type->validation_rules ?? [];

            $uploadData = $upload ? [
                'id' => $upload->id,
                'file_name' => $upload->file_name,
                'file_size_bytes' => $upload->file_size_bytes,
                'mime_type' => $upload->mime_type,
                'expires_at' => $upload->expires_at?->toIso8601String(),
                'uploaded_at' => $upload->created_at->toIso8601String(),
                'ocr_text' => $upload->ocr_text,
                'ai_analysis' => $upload->ai_analysis,
                'ai_insights' => $upload->ai_insights,
                'ai_suggestions' => $upload->ai_suggestions,
                'ai_status' => $upload->ai_status,
            ] : null;

            $result[] = [
                'code' => $type->code,
                'label' => $type->label,
                'scope' => $type->scope,
                'required' => $required,
                'mandatory' => $mandatory,
                'requires_expiration' => (bool) $type->requires_expiration,
                'max_file_size_mb' => $rules['max_file_size_mb'] ?? 10,
                'accepted_types' => $rules['accepted_types'] ?? ['pdf', 'jpg', 'png'],
                'order' => $activation->order,
                'upload' => $uploadData,
                'lifecycle_status' => DocumentLifecycleService::computeStatus($uploadData),
            ];
        }

        // Sort by order
        usort($result, fn ($a, $b) => $a['order'] <=> $b['order']);

        // ADR-170 Phase 3: Apply role-based doc_config overlay (same pattern as FieldResolverService)
        // doc_config is an OVERRIDE LAYER, not a whitelist:
        //   - docs NOT in doc_config remain visible with defaults
        //   - docs IN doc_config get their visible/required/order overrides
        //   - MANDATORY GUARD: mandatory documents CANNOT be hidden or made optional
        if (isset($role) && $role !== null && $role->doc_config !== null) {
            $configByCode = collect($role->doc_config)->keyBy('code');

            $result = collect($result)
                ->map(function ($doc) use ($configByCode) {
                    $config = $configByCode->get($doc['code']);
                    if (!$config) {
                        // Doc NOT in config — keep visible with defaults
                        return $doc;
                    }

                    // ── MANDATORY GUARD (absolute — no exception) ──
                    if ($doc['mandatory']) {
                        // Mandatory document: ignore visibility override, force visible + required
                        $doc['required'] = true;
                        $doc['order'] = $config['order'] ?? $doc['order'];

                        return $doc; // skip the null return — always visible
                    }

                    // Non-mandatory: apply overrides normally
                    if (($config['visible'] ?? true) === false) {
                        return null; // Hidden by role config (non-mandatory only)
                    }
                    $doc['required'] = $config['required'] ?? $doc['required'];
                    $doc['order'] = $config['order'] ?? $doc['order'];

                    return $doc;
                })
                ->filter()
                ->sortBy('order')
                ->values()
                ->toArray();
        }

        return $result;
    }
}
