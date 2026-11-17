<?php

namespace App\Core\Requirements;

use Illuminate\Support\Facades\DB;

/**
 * ADR-818 — WHO decides the requirements of a person in a company :
 *   - `role`     : historical computation (access role : doc_config / required_tags) — default, unchanged ;
 *   - `position` : the company's rules + the requirements of the person's POSITION (registry, ADR-817).
 * Read without side effect (never creates the settings row). Switching is explicit (RequirementAuthoritySwitch).
 */
final class RequirementAuthority
{
    public const ROLE = 'role';

    public const POSITION = 'position';

    /** @var array<int, string> request-scoped memo */
    private static array $memo = [];

    public static function of(int $companyId): string
    {
        $key = spl_object_id(app()).':'.$companyId; // mémo lié à l'instance applicative (jamais partagé entre tests/requêtes)

        return self::$memo[$key] ??= (DB::table('company_document_settings')->where('company_id', $companyId)->value('requirements_authority') ?: self::ROLE);
    }

    public static function isPosition(int $companyId): bool
    {
        return self::of($companyId) === self::POSITION;
    }

    public static function flush(?int $companyId = null): void
    {
        if ($companyId === null) {
            self::$memo = [];
        } else {
            unset(self::$memo[spl_object_id(app()).':'.$companyId]);
        }
    }
}
