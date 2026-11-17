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

    /**
     * ADR-818 — requirement decision for ONE subject, honouring the company's explicit authority :
     *   - `role` (default) : exactly `requirementMap($roleKey)` (historical, unchanged) ;
     *   - `position` : the company rules (no role overlay) + the requirements of the subject's POSITION
     *     from the registry (ADR-817). A document becomes required, never hidden ; role visibility rules
     *     (access) no longer shape the expected list. Mandatory catalogue rules keep applying.
     */
    public static function subjectMap(int $companyId, ?string $roleKey, ?int $jobRoleId, ?string $marketKey = null, ?string $scope = null): array
    {
        if (! \App\Core\Requirements\RequirementAuthority::isPosition($companyId)) {
            return static::requirementMap($companyId, $roleKey, $marketKey, $scope);
        }

        return static::positionMap($companyId, $jobRoleId, $marketKey, $scope);
    }

    /** ADR-818 — the TARGET decision (position authority), whatever the company's current authority (used by the preview). */
    public static function positionMap(int $companyId, ?int $jobRoleId, ?string $marketKey = null, ?string $scope = null): array
    {
        $map = static::requirementMap($companyId, null, $marketKey, $scope);
        if ($map === []) {
            return $map;
        }
        $resolved = static::positionRequirements($companyId, $jobRoleId);
        foreach ($map as $typeId => &$entry) {
            $entry['visible'] = true;
            if (($resolved['document:'.$typeId]['required'] ?? false) === true) {
                $entry['required'] = true;
            }
        }
        unset($entry);

        return $map;
    }

    /** @return array<string, array> registry resolution for a position (callers memoise per computation : never stale) */
    public static function positionRequirements(int $companyId, ?int $jobRoleId): array
    {
        $company = \App\Core\Models\Company::find($companyId);
        $job = $jobRoleId ? \App\Core\Workforce\JobRole::withoutGlobalScopes()->where('company_id', $companyId)->find($jobRoleId) : null;

        return $company ? \App\Core\Requirements\EffectiveRequirementResolver::forCompany($company)
            ->resolveFor(\App\Core\Requirements\EffectiveRequirementResolver::subjectForJobRole($job)) : [];
    }

    public static function resolve(
        ?User $user,
        int $companyId,
        ?string $roleKey = null,
        ?string $marketKey = null,
        ?string $scope = null,
    ): array {
        // ADR-790 : décision d'exigence partagée (requirementMap) ; ici, seulement la présentation.
        // ADR-818 : autorité explicite de la société (rôle par défaut ; poste si basculée).
        $jobRoleId = null;
        if ($user !== null && \App\Core\Requirements\RequirementAuthority::isPosition($companyId)) {
            $jobRoleId = \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $companyId)->where('user_id', $user->id)->value('job_role_id');
        }
        $map = static::subjectMap($companyId, $user !== null ? $roleKey : null, $jobRoleId ? (int) $jobRoleId : null, $marketKey, $scope);
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

        // ADR-810 : the role configuration defines the DEFAULT expected list ; it never hides a document that
        // concerns this person explicitly — an active / approved request addressed to her, or a file she has.
        // (Otherwise a requested, submitted then approved document vanished from her space and her file.)
        $explicit = [];
        if ($user !== null && $scope !== DocumentType::SCOPE_COMPANY) {
            $explicit = DocumentRequest::query()->where('company_id', $companyId)
                ->where(fn ($q) => $q->where(fn ($s) => $s->where('document_subject_type', DocumentSubject::TYPE_USER)->where('document_subject_id', $user->id))
                    ->orWhere('user_id', $user->id))
                ->whereIn('document_type_id', array_keys($map))
                ->where('status', '!=', DocumentRequest::STATUS_CANCELLED)
                ->pluck('document_type_id')->merge($uploads->keys())->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        $result = [];
        foreach ($map as $typeId => $entry) {
            if (! $entry['visible'] && ! in_array((int) $typeId, $explicit, true)) {
                continue; // masqué par la configuration du rôle (jamais un document obligatoire, ni demandé, ni détenu)
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
                'accepted_types' => \App\Core\Documents\Services\SubjectDocumentUploadService::acceptedTypes($rules), // ADR-813 : as enforced
                'order' => $entry['order'],
                'upload' => $uploadData,
                'lifecycle_status' => DocumentLifecycleService::computeStatus($uploadData),
            ];
        }

        usort($result, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $result;
    }
}
