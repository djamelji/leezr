<?php

namespace App\Modules\Platform\Jobdomains\Http;

use App\Core\Fields\CompanyCreationFields;
use App\Core\Fields\FieldDefinition;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Modules\Platform\Jobdomains\UseCases\UpdateJobdomainCompanyFieldUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-835 — Platform › JobDomains › Fields : the company creation fields of a JobDomain (inherited / customised). */
class JobdomainCompanyFieldController
{
    public function index(Request $request, int $id): JsonResponse
    {
        $jobdomain = Jobdomain::findOrFail($id);

        return response()->json([
            'fields' => CompanyCreationFields::configuration($jobdomain, app()->getLocale()),
            'markets' => Market::orderBy('sort_order')->get(['key', 'name', 'is_active'])->map(fn ($m) => ['key' => $m->key, 'name' => $m->name, 'is_active' => (bool) $m->is_active]),
        ]);
    }

    public function update(Request $request, int $id, int $fieldId, UpdateJobdomainCompanyFieldUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            'visible' => ['present', 'nullable', 'boolean'],
            'required' => ['present', 'nullable', 'boolean'],
            'markets' => ['present', 'nullable', 'array'],
            'markets.*' => ['string', 'max:10'],
            'default_value' => ['present', 'nullable', 'string', 'max:500'],
        ]);
        $jobdomain = Jobdomain::findOrFail($id);
        $useCase->customise($jobdomain, FieldDefinition::findOrFail($fieldId), $validated);

        return $this->index($request, $id);
    }

    public function destroy(Request $request, int $id, int $fieldId, UpdateJobdomainCompanyFieldUseCase $useCase): JsonResponse
    {
        $useCase->reset(Jobdomain::findOrFail($id), FieldDefinition::findOrFail($fieldId));

        return $this->index($request, $id);
    }
}
