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
    /**
     * ADR-790 (L0) — THE requirement decision for a role in a company, shared by every view
     * (fiche, dossier, self-service, conformité, tableau de bord). Same rules as before, in one place :
     *   required = mandatory (catalogue : modules actifs, étiquettes ∩ étiquettes requises du rôle)
     *              OR required_override (activation Company),
     *   then the role `doc_config` overlay (visible / required / order ; a mandatory document can never
     *   be hidden nor made optional).
     * No role (person without account, membership without role) → activation rules only.
     *
     * @return array<int, array{type: DocumentType, activation: DocumentTypeActivation, required: bool, mandatory: bool, visible: bool, order: int}>
     */
    public static function requirementMap(int $companyId, ?string $roleKey = null, ?string $marketKey = null, ?string $scope = null): array
    {
        $query = DocumentType::where(function ($q) use ($companyId) {
            $q->where('is_system', true)->orWhere('company_id', $companyId);
        })->whereNull('archived_at');
        if ($scope !== null) {
            $query->where('scope', $scope);
        }
        $types = $query->get()->keyBy('id');
        if ($marketKey) {
            $types = $types->filter(function ($type) use ($marketKey) {
                $markets = $type->validation_rules['applicable_markets'] ?? null;

                return $markets === null || in_array($marketKey, $markets);
            });
        }
        if ($types->isEmpty()) {
            return [];
        }
        $activations = DocumentTypeActivation::whereIn('document_type_id', $types->keys())
            ->where('company_id', $companyId)->where('enabled', true)->get()->keyBy('document_type_id');
        if ($activations->isEmpty()) {
            return [];
        }

        $context = DocumentMandatoryContext::load($companyId);
        $role = $roleKey !== null ? CompanyRole::where('company_id', $companyId)->where('key', $roleKey)->first() : null;
        $configByCode = $role?->doc_config !== null && $role !== null ? collect($role->doc_config)->keyBy('code') : collect();

        $map = [];
        foreach ($activations as $typeId => $activation) {
            $type = $types->get($typeId);
            if (! $type) {
                continue;
            }
            $mandatory = DocumentMandatoryContext::isMandatory($type, $context, $role?->required_tags);
            $required = $mandatory || $activation->required_override;
            $visible = true;
            $order = $activation->order;
            if ($config = $configByCode->get($type->code)) {
                $order = $config['order'] ?? $order;
                if ($mandatory) {
                    $required = true; // garde absolue : un document obligatoire reste visible et exigé
                } elseif (($config['visible'] ?? true) === false) {
                    $visible = false;
                } else {
                    $required = $config['required'] ?? $required;
                }
            }
            $map[$typeId] = ['type' => $type, 'activation' => $activation, 'required' => (bool) $required, 'mandatory' => $mandatory, 'visible' => $visible, 'order' => (int) $order];
        }

        return $map;
    }

    public static function resolve(
        ?User $user,
        int $companyId,
        ?string $roleKey = null,
        ?string $marketKey = null,
        ?string $scope = null,
    ): array {
        // ADR-790 : décision d'exigence partagée (requirementMap) ; ici, seulement la présentation.
        $map = static::requirementMap($companyId, $user !== null ? $roleKey : null, $marketKey, $scope);
        if ($map === []) {
            return [];
        }

        // Existing uploads for this user (skipped for company scope or null user)
        if ($user !== null && $scope !== DocumentType::SCOPE_COMPANY) {
            $uploads = MemberDocument::where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->whereIn('document_type_id', array_keys($map))
                ->get()
                ->keyBy('document_type_id');
        } else {
            $uploads = collect();
        }

        $result = [];
        foreach ($map as $typeId => $entry) {
            if (! $entry['visible']) {
                continue; // masqué par la configuration du rôle (jamais un document obligatoire)
            }
            $type = $entry['type'];
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
                'required' => $entry['required'],
                'mandatory' => $entry['mandatory'],
                'requires_expiration' => (bool) $type->requires_expiration,
                'max_file_size_mb' => $rules['max_file_size_mb'] ?? 10,
                'accepted_types' => $rules['accepted_types'] ?? ['pdf', 'jpg', 'png'],
                'order' => $entry['order'],
                'upload' => $uploadData,
                'lifecycle_status' => DocumentLifecycleService::computeStatus($uploadData),
            ];
        }

        usort($result, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $result;
    }
}
