<?php

namespace App\Modules\Platform\Jobdomains\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Fields\CompanyCreationFields;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\JobdomainCompanyField;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainPresetResolver;
use App\Core\Markets\Market;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * ADR-835 — a JobDomain customises (or stops customising) ONE company creation field of the global catalog,
 * within the legal and technical limits. Every change is audited (before/after).
 */
final class UpdateJobdomainCompanyFieldUseCase
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{visible?: ?bool, required?: ?bool, markets?: ?array, default_value?: ?string} $input null = inherited */
    public function customise(Jobdomain $jobdomain, FieldDefinition $def, array $input): JobdomainCompanyField
    {
        $this->assertCompanyField($def);
        $rules = $def->validation_rules ?? [];
        $inFieldList = collect(JobdomainPresetResolver::resolve($jobdomain->key, null)->fields)->contains('code', $def->code);
        $visible = array_key_exists('visible', $input) && $input['visible'] !== null ? (bool) $input['visible'] : $inFieldList;
        $required = array_key_exists('required', $input) && $input['required'] !== null ? (bool) $input['required'] : (bool) ($rules['required'] ?? false);
        $markets = array_key_exists('markets', $input) && $input['markets'] !== null ? array_values(array_unique($input['markets'])) : null;
        $default = array_key_exists('default_value', $input) ? trim((string) $input['default_value']) : '';
        $catalogMarkets = $rules['applicable_markets'] ?? null;
        $legal = CompanyCreationFields::legalMarkets($def);
        $errors = [];

        if ($required && ! $visible) {
            $errors['visible'] = [__('jobdomainCompanyFields.errors.requiredHidden')];
        }
        if ($markets !== null) {
            $unknown = array_diff($markets, Market::pluck('key')->all());
            $outside = $catalogMarkets === null ? [] : array_diff($markets, $catalogMarkets);
            if ($unknown !== [] || $outside !== []) {
                $errors['markets'] = [__('jobdomainCompanyFields.errors.marketsOutsideCatalog', ['markets' => implode(', ', $unknown ?: $outside)])];
            } elseif ($markets === []) {
                $errors['markets'] = [__('jobdomainCompanyFields.errors.noMarket')];
            }
        }
        if ($legal !== []) {
            $excluded = $markets === null ? [] : array_diff($legal, $markets);
            if (! $visible || ! $required || $excluded !== []) {
                $errors['required'] = [__('jobdomainCompanyFields.errors.legal', ['markets' => implode(', ', $legal)])];
            }
        }
        if ($default !== '') {
            $problem = CompanyCreationFields::valueProblem([
                'type' => $def->type, 'options' => $def->options, 'max' => $rules['max'] ?? null, 'pattern' => $rules['pattern'] ?? null,
            ], $default);
            if ($problem !== null) {
                $errors['default_value'] = [__('jobdomainCompanyFields.errors.default')];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $row = JobdomainCompanyField::firstOrNew(['jobdomain_id' => $jobdomain->id, 'field_definition_id' => $def->id]);
        $before = $row->exists ? $this->snapshot($row) : null;
        $row->fill([
            'visible' => $input['visible'] ?? null,
            'required' => $input['required'] ?? null,
            'markets' => $markets,
            'default_value' => $default === '' ? null : $default,
            'updated_by_platform_user_id' => Auth::guard('platform')->id(),
        ])->save();

        $this->audit->logPlatform(AuditAction::JOBDOMAIN_COMPANY_FIELD_UPDATED, 'jobdomain', (string) $jobdomain->id, [
            'metadata' => ['jobdomain' => $jobdomain->key, 'field' => $def->code],
            'diffBefore' => $before,
            'diffAfter' => $this->snapshot($row),
        ]);
        JobdomainPresetResolver::clearCache();

        return $row;
    }

    public function reset(Jobdomain $jobdomain, FieldDefinition $def): void
    {
        $this->assertCompanyField($def);
        $row = JobdomainCompanyField::where('jobdomain_id', $jobdomain->id)->where('field_definition_id', $def->id)->first();
        if (! $row) {
            return;
        }
        $before = $this->snapshot($row);
        $row->delete();
        $this->audit->logPlatform(AuditAction::JOBDOMAIN_COMPANY_FIELD_RESET, 'jobdomain', (string) $jobdomain->id, [
            'metadata' => ['jobdomain' => $jobdomain->key, 'field' => $def->code],
            'diffBefore' => $before,
            'diffAfter' => null,
        ]);
    }

    private function assertCompanyField(FieldDefinition $def): void
    {
        if ($def->company_id !== null || $def->scope !== FieldDefinition::SCOPE_COMPANY) {
            abort(404);
        }
    }

    private function snapshot(JobdomainCompanyField $row): array
    {
        return $row->only('visible', 'required', 'markets', 'default_value');
    }
}
