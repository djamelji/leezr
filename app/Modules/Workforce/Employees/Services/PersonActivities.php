<?php

namespace App\Modules\Workforce\Employees\Services;

use App\Core\Fields\FunctionActivity;
use App\Core\Fields\TagDictionary;
use App\Core\Workforce\Employee;
use Illuminate\Validation\Rule;

/**
 * ADR-862 — activities a person exercises, declared explicitly on their file (e.g. the occasional driver). Business
 * declaration, durable, with or without an account ; other metadata kept. Read by FunctionActivity.
 */
final class PersonActivities
{
    /** validation rules merged into the employee update (same right as assigning the position) */
    public static function rules(): array
    {
        return ['activities' => 'sometimes|array', 'activities.*' => ['string', Rule::in(TagDictionary::ACTIVITIES)]];
    }

    /** applies `activities` when present and returns the remaining validated attributes */
    public static function apply(Employee $employee, array $validated): array
    {
        if (! array_key_exists('activities', $validated)) {
            return $validated;
        }
        $employee->metadata = array_merge((array) ($employee->metadata ?? []), ['activities' => array_values(array_unique($validated['activities']))]);
        $employee->save();
        FunctionActivity::flush((int) $employee->company_id);
        unset($validated['activities']);

        return $validated;
    }
}
