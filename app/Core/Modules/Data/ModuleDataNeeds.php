<?php

namespace App\Core\Modules\Data;

use App\Company\RBAC\CompanyRole;
use App\Core\Audit\AuditLogger;
use App\Core\Fields\EmployeeFieldStore;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Workforce\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-820 — module DATA NEEDS bound to the company's own fields (generic mechanism, no business rule here).
 *
 *  - a module declares what it needs (type, population, uniqueness, the one operation that depends on it) ;
 *  - the company binds a need to an existing field, or creates the field from the assistant ;
 *  - candidates are COMPATIBLE fields only (type, individual, not sensitive) ; nothing is ever bound
 *    automatically, never because two labels look alike : the company confirms ;
 *  - the binding stores the field's stable id : renaming the label never breaks it ;
 *  - values are read through the canonical EmployeeFieldStore : nothing is copied ;
 *  - a missing value blocks only the operation that depends on it (`blocks`), once the need is bound.
 */
final class ModuleDataNeeds
{
    public const TYPES = [FieldDefinition::TYPE_STRING, FieldDefinition::TYPE_NUMBER, FieldDefinition::TYPE_DATE, FieldDefinition::TYPE_BOOLEAN, FieldDefinition::TYPE_SELECT];

    public function __construct(private Company $company) {}

    /** @return array<string, array> declared needs of a module, keyed by need key */
    public static function declared(string $moduleKey): array
    {
        $m = ModuleRegistry::definitions()[$moduleKey] ?? null;

        return collect($m?->dataNeeds ?? [])->keyBy('key')->all();
    }

    public static function allMapped(Company $company, string $moduleKey): bool
    {
        $keys = array_keys(self::declared($moduleKey));

        return $keys === [] || CompanyModuleFieldMapping::where('company_id', $company->id)->where('module_key', $moduleKey)->whereIn('need_key', $keys)->count() === count($keys);
    }

    public function mapping(string $moduleKey, string $needKey): ?CompanyModuleFieldMapping
    {
        return CompanyModuleFieldMapping::where('company_id', $this->company->id)->where('module_key', $moduleKey)->where('need_key', $needKey)->first();
    }

    /** Full state of a module's needs for the assistant (read only). */
    public function describe(string $moduleKey): array
    {
        $out = [];
        foreach (self::declared($moduleKey) as $key => $need) {
            $mapping = $this->mapping($moduleKey, $key);
            $field = $mapping ? FieldDefinition::find($mapping->field_definition_id) : null;
            [$compatible, $incompatible] = $this->candidates($need);
            $out[] = [
                'key' => $key, 'label' => $need['label'], 'hint' => $need['hint'] ?? null, 'type' => $need['type'],
                'unique' => (bool) ($need['unique'] ?? false), 'blocks' => $need['blocks'] ?? null,
                'population' => $need['population'] ?? null,
                'mapping' => $field ? ['field_definition_id' => $field->id, 'code' => $field->code, 'label' => $field->resolvedLabel(self::locale()),
                    'confirmed_at' => $mapping->confirmed_at?->toIso8601String()] : null,
                'candidates' => $compatible, 'incompatible' => $incompatible,
                'coverage' => $field ? $this->coverage($need, $field) : null,
                'can_create_field' => (bool) $this->company->jobdomain->allow_custom_fields,
            ];
        }

        return $out;
    }

    /** @return array{0: list<array>, 1: list<array>} compatible / incompatible fields with the reason */
    private function candidates(array $need): array
    {
        $active = FieldActivation::where('company_id', $this->company->id)->where('enabled', true)->pluck('field_definition_id')->flip();
        $fields = FieldDefinition::where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))
            ->whereIn('scope', [FieldDefinition::SCOPE_COMPANY_USER, FieldDefinition::SCOPE_COMPANY])
            ->orderBy('code')->get()
            ->filter(fn ($d) => $active->has($d->id) || $d->company_id === $this->company->id);
        $compatible = [];
        $incompatible = [];
        foreach ($fields as $d) {
            $row = ['id' => $d->id, 'code' => $d->code, 'label' => $d->resolvedLabel(self::locale()), 'type' => $d->type,
                'origin' => $d->company_id ? 'custom' : 'standard'];
            $reason = $this->incompatibility($need, $d);
            if ($reason === null) {
                $compatible[] = $row;
            } elseif ($reason !== 'collective') { // les champs de la fiche société ne sont pas listés (bruit)
                $incompatible[] = $row + ['reason' => $reason];
            }
        }
        // Les champs créés par la société d'abord (souvent l'identifiant métier qu'elle a déjà), puis l'ordre alphabétique.
        usort($compatible, fn ($a, $b) => [$a['origin'] !== 'custom', $a['label']] <=> [$b['origin'] !== 'custom', $b['label']]);

        return [$compatible, $incompatible];
    }

    private function incompatibility(array $need, FieldDefinition $d): ?string
    {
        return match (true) {
            $d->scope !== FieldDefinition::SCOPE_COMPANY_USER => 'collective', // un besoin individuel ≠ une donnée de la société
            $d->type !== $need['type'] => 'type',
            (bool) ($d->validation_rules['sensitive'] ?? false) => 'sensitive', // jamais exposer une donnée sensible à un module
            default => null,
        };
    }

    /** Bind a need to an existing compatible field (explicit confirmation). Activates the field if needed, never makes it required. */
    public function bind(string $moduleKey, string $needKey, int $fieldId, User $actor): array
    {
        $need = self::declared($moduleKey)[$needKey] ?? abort(404);
        abort_unless(ModuleGate::isActive($this->company, $moduleKey), 409, 'Module inactive.');
        $field = FieldDefinition::where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))->find($fieldId);
        if (! $field) {
            throw ValidationException::withMessages(['field_definition_id' => __('moduleData.unknown_field')]);
        }
        if ($reason = $this->incompatibility($need, $field)) {
            throw ValidationException::withMessages(['field_definition_id' => __('moduleData.incompatible.'.$reason)]);
        }
        DB::transaction(function () use ($moduleKey, $needKey, $field, $actor) {
            FieldActivation::firstOrCreate(['company_id' => $this->company->id, 'field_definition_id' => $field->id],
                ['enabled' => true, 'required_override' => false, 'order' => (int) $field->default_order]);
            FieldActivation::where('company_id', $this->company->id)->where('field_definition_id', $field->id)->where('enabled', false)->update(['enabled' => true]);
            $previous = $this->mapping($moduleKey, $needKey);
            CompanyModuleFieldMapping::updateOrCreate(['company_id' => $this->company->id, 'module_key' => $moduleKey, 'need_key' => $needKey],
                ['field_definition_id' => $field->id, 'confirmed_at' => now(), 'confirmed_by' => $actor->id]);
            app(AuditLogger::class)->logCompany($this->company->id, 'module.data_need_bound', 'module', $moduleKey, ['actorId' => $actor->id, 'actorType' => 'user',
                'metadata' => ['need' => $needKey, 'field' => $field->code, 'previous_field_id' => $previous?->field_definition_id]]);
        });

        return $this->describe($moduleKey);
    }

    /** Create the missing field from the assistant (same rules as the company field catalogue), then bind it. */
    public function createAndBind(string $moduleKey, string $needKey, string $label, User $actor): array
    {
        $need = self::declared($moduleKey)[$needKey] ?? abort(404);
        abort_unless(ModuleGate::isActive($this->company, $moduleKey), 409, 'Module inactive.');
        if (! $this->company->jobdomain->allow_custom_fields) {
            throw ValidationException::withMessages(['label' => __('moduleData.custom_fields_disabled')]);
        }
        if (FieldDefinition::where('company_id', $this->company->id)->count() >= FieldDefinition::MAX_CUSTOM_FIELDS_PER_COMPANY) {
            throw ValidationException::withMessages(['label' => __('moduleData.custom_fields_limit')]);
        }
        $base = Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '')->value() ?: $needKey;
        $base = preg_match('/^[a-z]/', $base) ? $base : 'f_'.$base;
        $code = $base;
        for ($i = 2; FieldDefinition::where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $this->company->id))->where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }
        $field = DB::transaction(function () use ($code, $label, $need, $actor, $moduleKey, $needKey) {
            $field = FieldDefinition::create(['company_id' => $this->company->id, 'code' => $code, 'scope' => FieldDefinition::SCOPE_COMPANY_USER,
                'label' => $label, 'type' => $need['type'], 'validation_rules' => ['max' => 120], 'is_system' => false, 'created_by_platform' => false, 'default_order' => 500]);
            app(AuditLogger::class)->logCompany($this->company->id, 'module.data_need_field_created', 'module', $moduleKey, ['actorId' => $actor->id, 'actorType' => 'user',
                'metadata' => ['need' => $needKey, 'field' => $code]]);

            return $field;
        });

        return $this->bind($moduleKey, $needKey, $field->id, $actor);
    }

    /**
     * Who the need concerns and how their data looks : filled / missing / invalid, duplicates when unique.
     * Values are read through the canonical store (nothing copied).
     */
    public function coverage(array $need, FieldDefinition $field): array
    {
        $people = $this->population($need);
        $values = $people->isEmpty() ? [] : (new EmployeeFieldStore($this->company))->rawValues($people, [$field->code]);
        $missing = [];
        $byValue = [];
        foreach ($people as $e) {
            $v = $values[$e->id][$field->code] ?? null;
            if ($v === null || $v === '') {
                $missing[] = ['employee_id' => $e->id, 'name' => trim($e->first_name.' '.$e->last_name)];
            } else {
                $byValue[(string) $v][] = trim($e->first_name.' '.$e->last_name);
            }
        }
        $duplicates = ($need['unique'] ?? false) ? collect($byValue)->filter(fn ($names) => count($names) > 1)
            ->map(fn ($names, $value) => ['value' => (string) $value, 'people' => $names])->values()->all() : [];

        return ['population' => $people->count(), 'filled' => $people->count() - count($missing), 'missing' => array_slice($missing, 0, 50),
            'missing_count' => count($missing), 'duplicates' => $duplicates];
    }

    /** People concerned : employees whose access role holds the population permission (never inferred from a label). */
    private function population(array $need): Collection
    {
        $permission = $need['population']['permission'] ?? null;
        $q = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('status', '!=', Employee::STATUS_TERMINATED);
        if ($permission) {
            $roleIds = CompanyRole::where('company_id', $this->company->id)->where('is_administrative', false)
                ->whereHas('permissions', fn ($p) => $p->where('key', $permission))->pluck('id');
            $userIds = DB::table('memberships')->where('company_id', $this->company->id)->whereIn('company_role_id', $roleIds)->pluck('user_id');
            $q->whereIn('user_id', $userIds);
        }

        return $q->orderBy('last_name')->get();
    }

    /** Value of a bound need for one account (null when unbound, missing or not disclosed). */
    public function valueFor(string $moduleKey, string $needKey, int $userId): ?string
    {
        $mapping = $this->mapping($moduleKey, $needKey);
        $field = $mapping ? FieldDefinition::find($mapping->field_definition_id) : null;
        $employee = $field ? Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->where('user_id', $userId)->first() : null;
        if (! $employee) {
            return null;
        }
        $v = (new EmployeeFieldStore($this->company))->rawValues([$employee], [$field->code])[$employee->id][$field->code] ?? null;

        return $v === null || $v === '' ? null : (string) $v;
    }

    /** Guard of the ONE operation that depends on a need : only when the company bound it. */
    public function assertAvailable(string $moduleKey, string $operation, int $userId): void
    {
        foreach (self::declared($moduleKey) as $key => $need) {
            if (($need['blocks'] ?? null) !== $operation || ! $this->mapping($moduleKey, $key)) {
                continue;
            }
            if ($this->valueFor($moduleKey, $key, $userId) === null) {
                throw ValidationException::withMessages(['assigned_to_user_id' => __('moduleData.missing_value', ['need' => __('moduleData.need.'.$key)])]);
            }
        }
    }

    private static function locale(): string
    {
        return substr((string) (\App\Core\Fields\FieldResolverService::requestLocale() ?? app()->getLocale()), 0, 2);
    }
}
