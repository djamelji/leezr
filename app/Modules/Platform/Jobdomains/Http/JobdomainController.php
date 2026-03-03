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
        ]);

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
        ]);

        $jobdomain = $useCase->execute(UpdateJobdomainData::fromValidated($id, $validated));

        return response()->json([
            'message' => 'Job domain updated.',
            'jobdomain' => $jobdomain,
        ]);
    }

    public function destroy(int $id, DeleteJobdomainUseCase $useCase): JsonResponse
    {
        $useCase->execute($id);

        return response()->json([
            'message' => 'Job domain deleted.',
        ]);
    }
}
