<?php

namespace App\Modules\Workforce\Exports\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Modules\ModuleGate;
use App\Modules\Workforce\Exports\TimeExportService;
use App\Modules\Workforce\Security\WorkforceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ADR-844 (Leezr 4.2 M7) — time / planning / absence exports. Route guarded by `workforce.export` ;
 * each dataset is further limited to its module and to the requester's visibility scope in its domain.
 */
class TimeExportController
{
    public function preview(Request $request): JsonResponse
    {
        [$service, $datasets] = $this->prepare($request, multiple: true);
        $out = [];
        foreach ($datasets as $dataset) {
            $out[$dataset] = $service->preview($dataset);
        }

        return response()->json([
            'filters' => $request->only(['from', 'to', 'employee_ids', 'work_location_id']),
            'timezone' => $service->timezone(),
            'datasets' => $out,
            'available' => $this->available($request),
            'limits' => ['max_rows' => TimeExportService::MAX_ROWS, 'max_days' => TimeExportService::MAX_DAYS],
        ]);
    }

    public function download(Request $request)
    {
        [$service, [$dataset]] = $this->prepare($request, multiple: false);
        $count = $service->count($dataset);
        if ($count > TimeExportService::MAX_ROWS) {
            throw ValidationException::withMessages(['dataset' => __('workforceExport.too_large', ['max' => TimeExportService::MAX_ROWS])]);
        }

        // sensitive personal data leaves the application : audited (filters and volume — never the content)
        app(AuditLogger::class)->logCompany($request->attributes->get('company')->id, AuditAction::WORKFORCE_TIME_EXPORTED, 'workforce_export', $dataset, [
            'metadata' => ['dataset' => $dataset, 'from' => $request->input('from'), 'to' => $request->input('to'),
                'employees_filter' => count((array) $request->input('employee_ids', [])), 'work_location_id' => $request->input('work_location_id'), 'rows' => $count],
        ]);

        return $service->stream($dataset, fn (string $column) => __("workforceExport.columns.$column"));
    }

    /** datasets the requester may export (module active), for the UI */
    private function available(Request $request): array
    {
        $company = $request->attributes->get('company');

        return array_values(array_filter(TimeExportService::DATASETS, fn ($d) => ModuleGate::isActive($company, TimeExportService::MODULE[$d])));
    }

    /** @return array{0: TimeExportService, 1: string[]} */
    private function prepare(Request $request, bool $multiple): array
    {
        $available = $this->available($request);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_ids' => ['sometimes', 'array', 'max:500'],
            'employee_ids.*' => ['integer'],
            'work_location_id' => ['sometimes', 'nullable', 'integer'],
            ...($multiple
                ? ['datasets' => ['required', 'array', 'min:1'], 'datasets.*' => [Rule::in($available)]]
                : ['dataset' => ['required', Rule::in($available)]]),
        ], ['datasets.*.in' => __('workforceExport.module_inactive'), 'dataset.in' => __('workforceExport.module_inactive')]);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) + 1 > TimeExportService::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('workforceExport.period_too_long', ['days' => TimeExportService::MAX_DAYS])]);
        }

        $datasets = $multiple ? array_values(array_unique($data['datasets'])) : [$data['dataset']];
        $scopes = [];
        foreach (array_unique(array_map(fn ($d) => TimeExportService::DOMAIN[$d], $datasets)) as $domain) {
            $scopes[$domain] = WorkforceAccess::scopeIdsOrNull($request, $domain); // null = everyone, else self + direct reports
        }

        return [new TimeExportService($request->attributes->get('company'), [
            'from' => $data['from'], 'to' => $data['to'],
            'employee_ids' => array_map('intval', $data['employee_ids'] ?? []),
            'work_location_id' => $data['work_location_id'] ?? null,
        ], $scopes), $datasets];
    }
}
