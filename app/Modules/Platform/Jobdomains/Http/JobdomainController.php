<?php

namespace App\Modules\Platform\Jobdomains\Http;

use App\Modules\Platform\Jobdomains\ReadModels\PlatformJobdomainReadModel;
use App\Modules\Platform\Jobdomains\UseCases\CreateJobdomainData;
use App\Modules\Platform\Jobdomains\UseCases\CreateJobdomainUseCase;
use App\Modules\Platform\Jobdomains\UseCases\DeleteJobdomainUseCase;
use App\Modules\Platform\Jobdomains\UseCases\UpdateJobdomainData;
use App\Modules\Platform\Jobdomains\UseCases\UpdateJobdomainUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class JobdomainController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'jobdomains' => PlatformJobdomainReadModel::catalog(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(PlatformJobdomainReadModel::detail($id));
    }

    public function store(Request $request, CreateJobdomainUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:jobdomains,key'],
            'label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'default_modules' => ['sometimes', 'array'],
            'default_modules.*' => ['string'],
            // ADR-169: default_fields = code + order only (no 'required' — catalog handles mandatory)
            'default_fields' => ['sometimes', 'array'],
            'default_fields.*.code' => ['required', 'string'],
            'default_fields.*.order' => ['sometimes', 'integer', 'min:0'],
            'sector' => ['nullable', 'string', 'max:100'],
        ]);
        self::assertLegacyEditable($validated, ['default_fields']); // ADR-797 : les champs passent par les presets d'exigences

        $jobdomain = $useCase->execute(CreateJobdomainData::fromValidated($validated));

        return response()->json([
            'message' => 'Job domain created.',
            'jobdomain' => $jobdomain,
        ]);
    }

    public function update(Request $request, int $id, UpdateJobdomainUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'allow_custom_fields' => ['sometimes', 'boolean'],
            'default_modules' => ['sometimes', 'array'],
            'default_modules.*' => ['string'],
            // ADR-169: default_fields = code + order only (no 'required')
            'default_fields' => ['sometimes', 'array'],
            'default_fields.*.code' => ['required', 'string'],
            'default_fields.*.order' => ['sometimes', 'integer', 'min:0'],
            'default_roles' => ['sometimes', 'array'],
            // ADR-179: default_documents = code + order only (same pattern as default_fields)
            'default_documents' => ['sometimes', 'array'],
            'default_documents.*.code' => ['required', 'string'],
            'default_documents.*.order' => ['sometimes', 'integer', 'min:0'],
            // ADR-797 — identité métier
            'sector' => ['nullable', 'string', 'max:100'],
            'terminology' => ['nullable', 'array', 'max:30'],
            'terminology.*' => ['nullable', 'string', 'max:60'],
            // ADR-799 — cadre conventionnel recommandé : références aux conventions existantes, par marché
            'convention_refs' => ['nullable', 'array', 'max:20'],
            'convention_refs.*.market_key' => ['required', 'string', 'exists:markets,key'],
            'convention_refs.*.convention_collective_id' => ['required', 'integer', 'exists:convention_collectives,id'],
        ]);
        foreach ($validated['convention_refs'] ?? [] as $i => $ref) {
            abort_if(\App\Core\Workforce\ConventionCollective::whereKey($ref['convention_collective_id'])->value('market_key') !== $ref['market_key'],
                response()->json(['message' => 'The convention does not belong to this market.', 'errors' => ["convention_refs.{$i}" => ['market mismatch']]], 422));
        }
        self::assertLegacyEditable($validated, ['default_fields', 'default_roles', 'default_documents']);

        $result = $useCase->execute(UpdateJobdomainData::fromValidated($id, $validated));

        $response = [
            'message' => 'Job domain updated.',
            'jobdomain' => $result['jobdomain'],
        ];

        // ADR-213: Include auto-resolved dependency changes for admin feedback
        if (! empty($result['auto_added']) || ! empty($result['auto_removed'])) {
            $response['auto_added'] = $result['auto_added'];
            $response['auto_removed'] = $result['auto_removed'];
        }

        return response()->json($response);
    }

    public function destroy(int $id, DeleteJobdomainUseCase $useCase): JsonResponse
    {
        $useCase->execute($id);

        return response()->json([
            'message' => 'Job domain deleted.',
        ]);
    }

    /**
     * ADR-797 (L7) — the historical per-JobDomain roles / fields / documents are no longer edited here : they
     * belong to the versioned presets (RBAC, Requirements). Still READ by company creation and resolution.
     * Technical escape hatch only : `jobdomains.legacy_editing` (off).
     */
    public static function assertLegacyEditable(array $data, array $keys): void
    {
        $touched = array_values(array_filter($keys, fn ($k) => array_key_exists($k, $data) && $data[$k] !== null));
        if ($touched !== [] && ! config('jobdomains.legacy_editing')) {
            abort(response()->json([
                'message' => 'Legacy JobDomain configuration is read-only : manage it through versioned presets.',
                'code' => 'legacy_configuration_read_only', 'fields' => $touched,
            ], 422));
        }
    }
}
