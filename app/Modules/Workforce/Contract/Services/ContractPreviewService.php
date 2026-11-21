<?php

namespace App\Modules\Workforce\Contract\Services;

use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\Services\DocumentVariableResolver;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\Security\WorkforceAccess;
use Illuminate\Http\Request;

/**
 * ADR-848 (Leezr 4.2 M9) — shared steps of contract preview / PDF preview / generation, moved verbatim from
 * ContractController where they were repeated : template visible to the company, current contract of the employee
 * (never created automatically), variables with compensation MASKED without the right, strict {{ var }} rendering.
 */
class ContractPreviewService
{
    public static function template(int $companyId, int $templateId): DocumentTemplate
    {
        return DocumentTemplate::where('subject_type', ContractTemplateService::SUBJECT)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            ->findOrFail($templateId);
    }

    /** Résout le contrat métier COURANT du collaborateur (jamais créé automatiquement). */
    public static function resolveContractSubject(int $companyId, int $employeeId): array
    {
        $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->find($employeeId);
        if (! $employee) {
            return ['error' => 'Collaborateur introuvable.', 'contract' => null];
        }
        $contract = EmploymentContract::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('employee_id', $employeeId)->where('is_current', true)->first()
            ?? EmploymentContract::withoutGlobalScopes()->where('company_id', $companyId)->where('employee_id', $employeeId)->latest('start_date')->first();
        if (! $contract) {
            return ['error' => 'Aucun contrat de travail pour ce collaborateur. Créez-le d\'abord dans sa fiche.', 'contract' => null];
        }

        return ['error' => null, 'contract' => $contract];
    }

    /** Variables of the contract ; the salary is masked when the user may not read compensation. */
    public static function variables(Request $request, EmploymentContract $contract, Company $company): array
    {
        $variables = DocumentVariableResolver::resolve('contract', $contract, $company);
        if (! WorkforceAccess::canReadCompensation($request)) {
            foreach (array_keys($variables) as $k) {
                if (self::isCompensationVar($k)) {
                    $variables[$k] = '•••';
                }
            }
        }

        return $variables;
    }

    /** Substitution STRICTE {{ var }} — aucune exécution de code (pas de Blade/PHP/JS). */
    public static function render(string $html, array $variables): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($variables) {
            $v = $variables[$m[1]] ?? '';

            return htmlspecialchars(is_scalar($v) ? (string) $v : '', ENT_QUOTES);
        }, $html);
    }

    public static function isCompensationVar(string $key): bool
    {
        return (bool) preg_match('/salary|compensation|rate|remuneration|salaire/i', $key);
    }
}
