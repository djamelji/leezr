<?php

namespace App\Core\Documents\Satisfaction;

use App\Core\Documents\DocumentSubject;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;

/**
 * ADR-819 (Company 2.0) — registry of the sources that can satisfy a document requirement.
 *
 * TYPE ≠ DOCUMENT FOURNI ≠ DEMANDE ≠ EXIGENCE : a proof only tells that ONE requirement (type code, person)
 * is met by an artefact that already exists elsewhere (e.g. a contract generated and signed in Workforce).
 * The requirement is satisfied once, whatever the source ; an upload stays the primary evidence and a
 * proof is only consulted when no satisfying upload exists. Nothing is written, nothing is copied.
 */
final class DocumentProofRegistry
{
    /** @var list<class-string<DocumentProofSource>> */
    private static array $sources = [];

    /** @param class-string<DocumentProofSource> $class */
    public static function register(string $class): void
    {
        if (! in_array($class, self::$sources, true)) {
            self::$sources[] = $class;
        }
    }

    /** @return list<string> */
    public static function provableCodes(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn ($c) => app($c)->documentTypeCodes(), self::$sources) ?: [[]])));
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<string, array> "employeeId:code" → proof (first source wins, deterministic order)
     */
    public static function forEmployees(Company $company, array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if ($employeeIds === [] || self::$sources === []) {
            return [];
        }
        // Tenant guard : only employees of this company are ever asked about.
        $employeeIds = Employee::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('id', $employeeIds)->pluck('id')->all();
        $out = [];
        foreach (self::$sources as $class) {
            foreach (app($class)->proofsFor($company, $employeeIds) as $employeeId => $byCode) {
                foreach ($byCode as $code => $proof) {
                    $out[$employeeId.':'.$code] ??= $proof;
                }
            }
        }

        return $out;
    }

    /**
     * Proofs for a person known through one or several document subjects (user and/or employee).
     *
     * @param  list<DocumentSubject>  $subjects
     * @return array<string, array> code → proof
     */
    public static function forSubjects(Company $company, array $subjects): array
    {
        $employeeId = null;
        foreach ($subjects as $s) {
            if ($s->type === DocumentSubject::TYPE_EMPLOYEE) {
                $employeeId = (int) $s->id;
                break;
            }
        }
        if ($employeeId === null) {
            foreach ($subjects as $s) {
                if ($s->type === DocumentSubject::TYPE_USER) {
                    $employeeId = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $s->id)->value('id');
                    if ($employeeId) {
                        break;
                    }
                }
            }
        }
        if (! $employeeId) {
            return [];
        }
        $out = [];
        foreach (self::forEmployees($company, [$employeeId]) as $key => $proof) {
            $out[substr($key, strpos($key, ':') + 1)] = $proof;
        }

        return $out;
    }

    /** Test helper. */
    public static function sources(): array
    {
        return self::$sources;
    }
}
