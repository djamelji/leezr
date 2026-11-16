<?php

namespace App\Modules\Platform\Presets\Http;

use App\Core\Documents\DocumentType;
use App\Core\Fields\FieldDefinition;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Core\Presets\Preset;
use App\Core\Presets\PresetService;
use App\Core\Requirements\CompanyRequirementResolver;
use App\Core\Requirements\Requirement;
use App\Core\Requirements\RequirementLevel;
use App\Modules\Workforce\Organization\OrganizationPreset;
use App\Company\RBAC\CompanyPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-798 — specialised preset editing (RBAC, Requirements, Planning ; Organisation keeps its editor) :
 * reference data for the editors, draft update with optimistic concurrency, guarded archive, and the
 * company-level requirements answer (read only). No company is modified by any action here.
 */
class PlatformPresetEditorController extends Controller
{
    public function meta(): JsonResponse
    {
        $adminPerms = CompanyPermission::where('is_admin', true)->pluck('key')->flip();
        $bundles = collect(ModuleRegistry::forScope('company'))->flatMap(fn ($m) => collect($m->bundles)->map(fn ($b) => [
            'key' => $b['key'], 'label' => $b['label'], 'module' => $m->name, 'is_admin' => (bool) ($b['is_admin'] ?? false),
            'sensitive' => collect($b['permissions'] ?? [])->contains(fn ($p) => isset($adminPerms[$p])), 'permissions' => count($b['permissions'] ?? []),
        ]))->values();
        $label = fn ($d) => method_exists($d, 'resolvedLabel') ? $d->resolvedLabel(app()->getLocale()) : ($d->label ?? $d->code);
        $fields = FieldDefinition::whereNull('company_id')->whereIn('scope', [FieldDefinition::SCOPE_COMPANY, FieldDefinition::SCOPE_COMPANY_USER])->orderBy('code')->get()
            ->map(fn ($d) => ['code' => $d->code, 'label' => $label($d), 'subject' => $d->scope === FieldDefinition::SCOPE_COMPANY ? 'company' : 'person', 'sensitive' => (bool) ($d->validation_rules['sensitive'] ?? false)]);
        $documents = DocumentType::where('is_system', true)->whereIn('scope', [DocumentType::SCOPE_COMPANY, DocumentType::SCOPE_COMPANY_USER])->orderBy('code')->get()
            ->map(fn ($d) => ['code' => $d->code, 'label' => $d->validation_rules['translations'][app()->getLocale()] ?? $d->label, 'subject' => $d->scope === DocumentType::SCOPE_COMPANY ? 'company' : 'person']);
        // Postes connus : ceux des presets d'organisation publiés (clés stables utilisées par les sociétés).
        $positions = OrganizationPreset::where('status', 'published')->get()->flatMap(fn ($p) => collect($p->structure['job_roles'] ?? $p->structure['positions'] ?? [])
            ->map(fn ($j) => ['key' => $j['key'] ?? $j['preset_item_key'] ?? null, 'label' => $j['title'] ?? $j['label'] ?? null]))->filter(fn ($j) => $j['key'])->unique('key')->values();

        return response()->json([
            'bundles' => $bundles, 'fields' => $fields, 'documents' => $documents, 'positions' => $positions,
            'levels' => [RequirementLevel::RECOMMENDED, RequirementLevel::DEFAULT, RequirementLevel::CONFIGURABLE_REQUIRED],
            'subjects' => Requirement::SUBJECTS, 'stages' => ['hire', 'onboarding', 'ongoing'],
            'archetypes' => collect(\App\Core\Jobdomains\JobdomainRegistry::definitions())->flatMap(fn ($d) => array_keys($d['archetypes'] ?? []))->unique()->values(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['label' => 'sometimes|string|max:255', 'description' => 'nullable|string|max:2000', 'content' => 'sometimes|array',
            'requires_modules' => 'nullable|array', 'requires_modules.*' => 'string|max:100', 'revision' => 'nullable|integer|min:0']);
        $revision = isset($data['revision']) ? (int) $data['revision'] : null; // l'éditeur l'envoie toujours (contrôle de concurrence)
        unset($data['revision']);

        return response()->json(PresetService::updateDraft(Preset::findOrFail($id), $data, $request->user()?->id, $revision));
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        PresetService::archive($preset = Preset::findOrFail($id), $request->user()?->id);

        return response()->json($preset->fresh());
    }

    public function companyRequirements(int $companyId): JsonResponse
    {
        return response()->json(CompanyRequirementResolver::resolve(Company::query()->where('id', $companyId)->firstOrFail()));
    }
}
