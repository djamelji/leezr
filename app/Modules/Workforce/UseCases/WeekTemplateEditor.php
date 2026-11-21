<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Workforce\Employee;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\WeekTemplate;
use App\Core\Workforce\WeekTemplateLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADR-848 (Leezr 4.2 M9) — writes of week templates, moved verbatim from WeekTemplateController : create / update
 * (transaction : header + lines replaced), capture of an existing week as a NOMINATIVE template, and the validation
 * of the lines' references (company ownership, kind / employee / headcount coherence).
 */
class WeekTemplateEditor
{
    public function create(int $companyId, array $data): WeekTemplate
    {
        return DB::transaction(function () use ($companyId, $data) {
            $wt = WeekTemplate::create([
                'company_id' => $companyId, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'strategy' => $data['strategy'] ?? 'nominative', 'scope' => $data['scope'] ?? 'company',
                'department_id' => $data['department_id'] ?? null, 'work_location_id' => $data['work_location_id'] ?? null,
                'enabled' => $data['enabled'] ?? true,
            ]);
            $this->syncLines($wt, $data['lines'] ?? []);

            return $wt;
        });
    }

    public function update(WeekTemplate $wt, array $data): void
    {
        DB::transaction(function () use ($wt, $data) {
            $wt->fill(collect($data)->only(['name', 'description', 'strategy', 'scope', 'department_id', 'work_location_id', 'enabled'])->toArray())->save();
            if (array_key_exists('lines', $data)) {
                $wt->lines()->delete();
                $this->syncLines($wt, $data['lines'] ?? []);
            }
        });
    }

    /** Capture une semaine existante en semaine type NOMINATIVE (références figées : jour, modèle, salarié). */
    public function capture(int $companyId, string $name, Carbon $weekStart, Collection $shifts): WeekTemplate
    {
        return DB::transaction(function () use ($companyId, $name, $weekStart, $shifts) {
            $wt = WeekTemplate::create([
                'company_id' => $companyId, 'name' => $name, 'strategy' => 'nominative', 'scope' => 'company', 'enabled' => true,
            ]);
            $order = 0;
            foreach ($shifts as $s) {
                $dayIndex = (int) $weekStart->diffInDays(Carbon::parse($s->date->toDateString()), false);
                if ($dayIndex < 0 || $dayIndex > 6) {
                    continue;
                }
                WeekTemplateLine::create([
                    'company_id' => $companyId, 'week_template_id' => $wt->id, 'day_index' => $dayIndex,
                    'schedule_template_id' => $s->schedule_template_id, 'kind' => 'nominative', 'employee_id' => $s->employee_id,
                    'work_location_id' => $s->work_location_id, 'sort_order' => $order++,
                ]);
            }

            return $wt;
        });
    }

    /** Valide l'appartenance company des références + la cohérence kind/salarié/effectif. */
    public function linesError(int $companyId, array $lines): ?string
    {
        foreach ($lines as $ln) {
            if (! ScheduleTemplate::withoutGlobalScopes()->where('id', $ln['schedule_template_id'])->where('company_id', $companyId)->exists()) {
                return 'A schedule template in a line does not belong to this company.';
            }
            if (($ln['kind'] ?? null) === 'nominative') {
                if (empty($ln['employee_id'])) {
                    return 'A nominative line requires an employee.';
                }
                if (! Employee::withoutGlobalScopes()->where('id', $ln['employee_id'])->where('company_id', $companyId)->exists()) {
                    return 'An employee in a line does not belong to this company.';
                }
            } elseif (($ln['kind'] ?? null) === 'needs' && empty($ln['headcount'])) {
                return 'A needs line requires a headcount.';
            }
        }

        return null;
    }

    private function syncLines(WeekTemplate $wt, array $lines): void
    {
        $order = 0;
        foreach ($lines as $ln) {
            WeekTemplateLine::create([
                'company_id' => $wt->company_id, 'week_template_id' => $wt->id,
                'day_index' => (int) $ln['day_index'], 'schedule_template_id' => (int) $ln['schedule_template_id'],
                'kind' => $ln['kind'], 'employee_id' => $ln['employee_id'] ?? null,
                'headcount' => $ln['kind'] === 'needs' ? (int) ($ln['headcount'] ?? 1) : null,
                'work_location_id' => $ln['work_location_id'] ?? null, 'department_id' => $ln['department_id'] ?? null,
                'coverage_category_id' => $ln['coverage_category_id'] ?? null, 'sort_order' => $ln['sort_order'] ?? $order++,
            ]);
        }
    }
}
