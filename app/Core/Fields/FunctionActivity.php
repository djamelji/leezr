<?php

namespace App\Core\Fields;

use App\Core\Requirements\RequirementAuthority;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Support\Facades\DB;

/**
 * ADR-861 (Leezr 5.0 L7.6) — who exercises an activity (TagDictionary tag) in a company, to scope the person fields
 * a module requires FOR THAT ACTIVITY (`required_by_modules` + `tags`, e.g. the Fleet driving licence).
 *
 * Company switch `company_document_settings.module_obligation_scope` :
 *   - 'everyone' (default, NULL) : historical behaviour — an active module requires its fields from everyone ;
 *   - 'function'                 : the module requires them only from people who exercise the activity.
 *
 * A person exercises an activity when ANY of these is true (never the access role alone) :
 *   - position  : the position (JobRole) declares it in `metadata.activities` (covers people without a Leezr account) ;
 *   - person    : ADR-862 — the person's file declares it explicitly in `workforce_employees.metadata.activities`
 *                 (the occasional driver : a business declaration, durable, with or without an account) ;
 *   - role tags : the access role requires the tag — ROLE authority only (ADR-820 : with the POSITION authority the
 *                 access role never makes a field required).
 * ADR-862 — module evidence (e.g. deliveries assigned in the last 90 days) is a HINT, never a source : it is reported
 * (simulation « to declare », reasons) so that someone declares the activity, but it does not by itself make a field
 * required (an expiring history is not a durable obligation).
 * What the company configured explicitly (catalogue « required for all », per-role rules, position requirements,
 * Platform « required ») is untouched : this only narrows the module-wide part.
 */
final class FunctionActivity
{
    public const SCOPE_EVERYONE = 'everyone';
    public const SCOPE_FUNCTION = 'function';

    /** @var array<string, list<class-string<FunctionActivityEvidence>>> */
    private static array $providers = [];
    private static array $memo = [];

    /** @param class-string<FunctionActivityEvidence> $class */
    public static function provide(string $tag, string $class): void
    {
        if (! in_array($class, self::$providers[$tag] ?? [], true)) {
            self::$providers[$tag][] = $class;
        }
    }

    public static function scopeOf(int $companyId): string
    {
        return self::memo($companyId)['scope'];
    }

    public static function isFunctionScoped(?int $companyId): bool
    {
        return $companyId !== null && self::scopeOf($companyId) === self::SCOPE_FUNCTION;
    }

    /** Activity tags of an account member (role tags are passed by the caller, which already loaded the role). */
    public static function tagsForUser(int $companyId, int $userId, ?array $roleTags = null): array
    {
        $m = self::memo($companyId);
        $tags = array_merge($m['declared_user'][$userId] ?? [], $m['positions'][$m['position_of_user'][$userId] ?? 0] ?? []);
        if (! $m['position_authority']) {
            $tags = array_merge($tags, (array) $roleTags);
        }

        return array_values(array_unique($tags));
    }

    /** Activity tags of an employee file — with or without a login account. */
    public static function tagsForEmployee(int $companyId, Employee $e, ?array $roleTags = null): array
    {
        if ($e->user_id) {
            return self::tagsForUser($companyId, (int) $e->user_id, $roleTags);
        }
        $m = self::memo($companyId);

        return array_values(array_unique(array_merge($m['positions'][(int) $e->job_role_id] ?? [], $m['declared_employee'][(int) $e->id] ?? [])));
    }

    /** Why a person exercises a tag — for the simulation report. @return list<string> */
    public static function reasons(int $companyId, ?int $userId, ?int $jobRoleId, string $tag, ?array $roleTags = null, ?int $employeeId = null): array
    {
        $m = self::memo($companyId);
        $jobRoleId ??= $userId ? ($m['position_of_user'][$userId] ?? null) : null;
        $declared = ($userId && in_array($tag, $m['declared_user'][$userId] ?? [], true))
            || ($employeeId && in_array($tag, $m['declared_employee'][$employeeId] ?? [], true));

        return array_values(array_filter([
            in_array($tag, $m['positions'][(int) $jobRoleId] ?? [], true) ? 'position' : null,
            $declared ? 'person' : null,
            ! $m['position_authority'] && in_array($tag, (array) $roleTags, true) ? 'role' : null,
        ]));
    }

    /** ADR-862 — a module's evidence for this person (a hint to declare the activity, never an obligation). */
    public static function hinted(int $companyId, ?int $userId, string $tag): bool
    {
        return $userId !== null && in_array($tag, self::memo($companyId)['hints'][$userId] ?? [], true);
    }

    /** Writes the switch (callers guard WHO may write : clone-only command, explicit company action). Returns the previous value. */
    public static function setScope(int $companyId, string $scope): ?string
    {
        $previous = DB::table('company_document_settings')->where('company_id', $companyId)->value('module_obligation_scope');
        DB::table('company_document_settings')->updateOrInsert(['company_id' => $companyId], ['module_obligation_scope' => $scope, 'updated_at' => now()]);
        self::flush($companyId);
        MandatoryContext::flush();

        return $previous;
    }

    public static function flush(?int $companyId = null): void
    {
        if ($companyId === null) {
            self::$memo = [];
        } else {
            unset(self::$memo[spl_object_id(app()).':'.$companyId]);
        }
    }

    /** Runs $fn as if the company had chosen the function scope — read only (simulation), the memo is dropped afterwards. */
    public static function simulate(int $companyId, callable $fn): mixed
    {
        self::flush($companyId);
        self::memo($companyId, self::SCOPE_FUNCTION);
        try {
            return $fn();
        } finally {
            self::flush($companyId);
        }
    }

    private static function memo(int $companyId, ?string $force = null): array
    {
        $key = spl_object_id(app()).':'.$companyId; // one memo per application instance (tests, Octane-safe like RequirementAuthority)
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $scope = $force ?? (DB::table('company_document_settings')->where('company_id', $companyId)->value('module_obligation_scope') ?: self::SCOPE_EVERYONE);
        $m = ['scope' => $scope, 'position_authority' => RequirementAuthority::isPosition($companyId), 'positions' => [], 'position_of_user' => [], 'declared_user' => [], 'declared_employee' => [], 'hints' => []];
        if ($scope === self::SCOPE_FUNCTION) {
            foreach (JobRole::withoutGlobalScopes()->where('company_id', $companyId)->get(['id', 'metadata']) as $jr) {
                $acts = array_values(array_filter((array) ($jr->metadata['activities'] ?? []), 'is_string'));
                if ($acts) {
                    $m['positions'][$jr->id] = $acts;
                }
            }
            $employees = Employee::withoutGlobalScopes()->where('company_id', $companyId)->where('status', '!=', 'terminated')
                ->get(['id', 'user_id', 'job_role_id', 'metadata']);
            foreach ($employees as $e) {
                if ($e->user_id) {
                    $m['position_of_user'][(int) $e->user_id] = (int) $e->job_role_id;
                }
                $acts = array_values(array_filter((array) ($e->metadata['activities'] ?? []), 'is_string'));
                if ($acts) {
                    $m['declared_employee'][$e->id] = $acts;
                    if ($e->user_id) {
                        $m['declared_user'][(int) $e->user_id] = $acts;
                    }
                }
            }
            foreach (self::$providers as $tag => $classes) {
                foreach ($classes as $class) {
                    foreach ($class::holders($companyId) as $uid) {
                        $m['hints'][(int) $uid][] = $tag;
                    }
                }
            }
        }

        return self::$memo[$key] = $m;
    }
}
